<?php

namespace Tests\Feature;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Livewire\Goals\GoalsIndex;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\GoalBehindPace;
use App\Services\GoalPaceService;
use App\Support\NotificationPresenter;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Objetivo atrasado: ritmo real (3 meses FECHADOS, depois do mês de criação) contra o "quanto vou guardar por mês", a faixa na lista e o aviso mensal
 * idempotente que respeita a privacidade do casal. Hoje é 12/11/2026, então os meses fechados são agosto, setembro e outubro.
 */
class GoalPaceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private ProfileMember $bruno;

    private User $usuarioAna;

    private User $usuarioBruno;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));

        $this->usuarioAna = User::factory()->create();
        $this->usuarioBruno = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioAna->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioAna->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->bruno = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioBruno->id, 'role' => MemberRole::Secondary, 'name' => 'Bruno']);
        $this->actingAs($this->usuarioAna);
        app(ProfileContext::class)->set($this->perfil, $this->ana);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Objetivo de R$ 10.000, planejado R$ 500 por mês, criado em 10/06 (cobre os três meses fechados). */
    private function objetivo(array $campos = [], string $criadoEm = '2026-06-10'): Goal
    {
        $g = Goal::create(array_merge([
            'name' => 'Viagem', 'priority' => 1, 'estimated_value' => '10000.00', 'current_amount' => '0.00', 'funding_method' => FundingMethod::LumpSum,
            'status' => GoalStatus::Active, 'installment_amount' => '500.00',
        ], $campos));

        Goal::withoutGlobalScopes()->whereKey($g->id)->update(['created_at' => $criadoEm.' 10:00:00']);

        return Goal::query()->findOrFail($g->id);
    }

    private function aporte(Goal $g, string $valor, string $data): GoalContribution
    {
        return GoalContribution::create(['profile_id' => $g->profile_id, 'goal_id' => $g->id, 'member_id' => $g->member_id, 'is_private' => (bool) $g->is_private, 'amount' => $valor, 'contributed_on' => $data]);
    }

    private function ritmo(Goal $g): ?array
    {
        return app(GoalPaceService::class)->forGoal(Goal::query()->withContributionTotal()->findOrFail($g->id));
    }

    // ------------------------------------------------------------------ a medida

    public function test_guardar_bem_menos_que_o_planejado_deixa_o_objetivo_atrasado(): void
    {
        $g = $this->objetivo();
        $this->aporte($g, '400.00', '2026-08-15');
        $this->aporte($g, '100.00', '2026-09-15');

        $r = $this->ritmo($g);

        self::assertTrue($r['late']);
        self::assertSame(3, $r['months']);
        self::assertSame('500.00', $r['total']);
        self::assertSame('166.67', $r['average']);
        self::assertSame('500.00', $r['planned']);
        self::assertSame(33.33, $r['share']);
    }

    public function test_no_ritmo_planejado_nao_esta_atrasado(): void
    {
        $g = $this->objetivo();
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $d) {
            $this->aporte($g, '500.00', $d);
        }

        self::assertFalse($this->ritmo($g)['late']);
    }

    public function test_o_limite_e_setenta_por_cento_do_planejado(): void
    {
        $g = $this->objetivo();
        $this->aporte($g, '1050.00', '2026-09-10'); // 70% de 1.500

        self::assertFalse($this->ritmo($g)['late'], 'exatamente 70% ainda não é atraso');

        $g2 = $this->objetivo(['name' => 'Carro']);
        $this->aporte($g2, '1049.99', '2026-09-10');

        self::assertTrue($this->ritmo($g2)['late']);
    }

    public function test_o_mes_de_hoje_nao_entra_na_conta(): void
    {
        $g = $this->objetivo();
        $this->aporte($g, '100.00', '2026-10-10');
        $this->aporte($g, '5000.00', '2026-11-03'); // mês aberto: não salva nem condena

        $r = $this->ritmo($g);

        self::assertSame('100.00', $r['total']);
        self::assertTrue($r['late']);
    }

    public function test_aporte_mais_antigo_que_a_janela_nao_conta(): void
    {
        $g = $this->objetivo();
        $this->aporte($g, '9000.00', '2026-07-31'); // antes de agosto
        $this->aporte($g, '100.00', '2026-10-10');

        self::assertSame('100.00', $this->ritmo($g)['total']);
    }

    public function test_retirada_desconta_e_o_total_nunca_fica_negativo(): void
    {
        $g = $this->objetivo(['current_amount' => '2000.00']);
        $this->aporte($g, '300.00', '2026-08-10');
        $this->aporte($g, '-1000.00', '2026-09-10');

        $r = $this->ritmo($g);

        self::assertSame('0.00', $r['total']);
        self::assertSame('0.00', $r['average']);
        self::assertTrue($r['late']);
    }

    public function test_objetivo_novo_so_e_medido_depois_de_dois_meses_fechados_depois_da_criacao(): void
    {
        // Criado em outubro: o único mês fechado depois da criação seria nenhum (outubro é o mês parcial da criação).
        $novo = $this->objetivo(['name' => 'Novo'], '2026-10-20');
        $this->aporte($novo, '10.00', '2026-10-25');
        self::assertNull($this->ritmo($novo));

        // Criado em setembro: só outubro está fechado depois da criação (1 mês é pouco).
        $umMes = $this->objetivo(['name' => 'Um mês'], '2026-09-20');
        $this->aporte($umMes, '10.00', '2026-10-05');
        self::assertNull($this->ritmo($umMes));

        // Criado em agosto: setembro e outubro (2 meses), planejado 2 x 500.
        $doisMeses = $this->objetivo(['name' => 'Dois meses'], '2026-08-20');
        $this->aporte($doisMeses, '10.00', '2026-10-05');
        $r = $this->ritmo($doisMeses);
        self::assertSame(2, $r['months']);
        self::assertTrue($r['late']);
    }

    public function test_quem_nunca_registrou_aporte_nao_e_julgado(): void
    {
        self::assertNull($this->ritmo($this->objetivo()), 'sem histórico, talvez a pessoa guarde por fora');
    }

    public function test_sem_planejado_alcancado_ou_ligado_nao_ha_o_que_medir(): void
    {
        $semPlano = $this->objetivo(['name' => 'Sem plano', 'installment_amount' => null]);
        $this->aporte($semPlano, '10.00', '2026-10-05');
        self::assertNull($this->ritmo($semPlano));

        $alcancado = $this->objetivo(['name' => 'Feito', 'estimated_value' => '100.00', 'current_amount' => '100.00']);
        $this->aporte($alcancado, '10.00', '2026-10-05');
        self::assertNull($this->ritmo($alcancado));

        $investimento = InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id]);
        $ligado = $this->objetivo(['name' => 'Ligado', 'linked_investment_id' => $investimento->id]);
        self::assertNull($this->ritmo($ligado));

        $pausado = $this->objetivo(['name' => 'Pausado', 'status' => GoalStatus::Achieved]);
        $this->aporte($pausado, '10.00', '2026-10-05');
        self::assertNull($this->ritmo($pausado));
    }

    public function test_medir_varios_objetivos_usa_duas_consultas_e_nao_uma_por_objetivo(): void
    {
        $objetivos = collect(range(1, 6))->map(function (int $i) {
            $g = $this->objetivo(['name' => "O{$i}", 'priority' => $i]);
            $this->aporte($g, '10.00', '2026-10-05');

            return $g;
        });

        $lista = Goal::query()->active()->withContributionTotal()->get();

        DB::enableQueryLog();
        $ritmos = app(GoalPaceService::class)->forGoals($lista);
        $consultas = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'goal_contributions'))->count();
        DB::disableQueryLog();

        self::assertCount(6, $ritmos);
        self::assertSame(2, $consultas);
    }

    // ------------------------------------------------------------------ a frase

    public function test_a_frase_diz_o_ritmo_e_quanto_guardar_para_chegar_na_data_alvo(): void
    {
        $g = $this->objetivo(['target_date' => '2027-05-12', 'current_amount' => '1000.00']);
        $this->aporte($g, '300.00', '2026-09-10');
        $g = Goal::query()->withContributionTotal()->findOrFail($g->id);

        $servico = app(GoalPaceService::class);
        $frase = $servico->line($g, $servico->forGoal($g));

        self::assertStringContainsString('Nos últimos 3 meses fechados', $frase);
        self::assertStringContainsString('em média', $frase);
        self::assertStringContainsString('Para chegar na data-alvo, guarde', $frase);

        $semAporte = $this->objetivo(['name' => 'Parado'], '2026-06-10');
        $this->aporte($semAporte, '5.00', '2026-05-01'); // histórico existe, mas fora da janela
        $semAporte = Goal::query()->withContributionTotal()->findOrFail($semAporte->id);
        self::assertStringContainsString('nenhum aporte foi registrado', $servico->line($semAporte, $servico->forGoal($semAporte)));
    }

    // ------------------------------------------------------------------ a lista

    public function test_a_lista_mostra_a_faixa_so_no_objetivo_atrasado(): void
    {
        $atrasado = $this->objetivo(['name' => 'Atrasado']);
        $this->aporte($atrasado, '100.00', '2026-10-10');
        $emDia = $this->objetivo(['name' => 'Em dia', 'priority' => 2]);
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $d) {
            $this->aporte($emDia, '500.00', $d);
        }

        $html = Livewire::test(GoalsIndex::class)->assertSee('Abaixo do planejado.')->html();

        self::assertSame(1, substr_count($html, 'data-goal-behind-pace'), 'só o atrasado ganha a faixa');
    }

    // ------------------------------------------------------------------ o aviso

    public function test_o_aviso_chega_uma_vez_por_mes_e_a_rotina_repetida_nao_duplica(): void
    {
        Notification::fake();
        $g = $this->objetivo();
        $this->aporte($g, '100.00', '2026-10-10');
        $servico = app(GoalPaceService::class);

        // Objetivo compartilhado: os dois do casal recebem.
        self::assertSame(2, $servico->notifyBehind());
        self::assertSame(0, $servico->notifyBehind(), 'a mesma rodada de novo não avisa de novo');
        Notification::assertSentToTimes($this->usuarioAna, GoalBehindPace::class, 1);
        Notification::assertSentToTimes($this->usuarioBruno, GoalBehindPace::class, 1);

        // O mês seguinte lembra outra vez (agosto sai da janela, que passa a ser set/out/nov... o objetivo segue atrasado).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-12 09:00:00'));
        self::assertSame(2, $servico->notifyBehind());
    }

    public function test_objetivo_privado_so_avisa_o_dono(): void
    {
        Notification::fake();
        $g = $this->objetivo(['member_id' => $this->ana->id, 'is_private' => true]);
        $this->aporte($g, '100.00', '2026-10-10');

        self::assertSame(1, app(GoalPaceService::class)->notifyBehind());
        Notification::assertSentTo($this->usuarioAna, GoalBehindPace::class);
        Notification::assertNotSentTo($this->usuarioBruno, GoalBehindPace::class);
    }

    public function test_objetivo_em_dia_ou_sem_historico_nao_avisa(): void
    {
        Notification::fake();
        $emDia = $this->objetivo(['name' => 'Em dia']);
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $d) {
            $this->aporte($emDia, '500.00', $d);
        }
        $this->objetivo(['name' => 'Sem histórico', 'priority' => 2]);

        self::assertSame(0, app(GoalPaceService::class)->notifyBehind());
        Notification::assertNothingSent();
    }

    public function test_o_aviso_leva_o_texto_pronto_e_aparece_na_central(): void
    {
        $g = $this->objetivo(['name' => 'Viagem']);
        $this->aporte($g, '100.00', '2026-10-10');

        app(GoalPaceService::class)->notifyBehind();

        $linha = $this->usuarioAna->notifications()->first();
        $dados = $linha->data;
        self::assertSame('goal_behind_pace', $dados['kind']);
        self::assertSame('Viagem', $dados['title']);
        self::assertSame($g->id, $dados['goal_id']);

        $apresentado = NotificationPresenter::present($linha);
        self::assertSame('Objetivo abaixo do planejado', $apresentado['heading']);
        self::assertStringContainsString('Viagem: Nos últimos', $apresentado['message']);
        self::assertSame(route('goals.index'), $apresentado['url']);
    }
}
