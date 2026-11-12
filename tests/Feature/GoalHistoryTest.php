<?php

namespace Tests\Feature;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Livewire\Goals\GoalHistory;
use App\Livewire\Goals\GoalsIndex;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\GoalContribution;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DashboardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Histórico de aportes do objetivo: registrar aporte e retirada, o saldo inicial + aportes como progresso, a linha do tempo mês a mês, o ritmo real
 * dos últimos 3 meses, edição, exclusão e o isolamento (outro objetivo, outro perfil, privacidade do casal). Hoje é 12/11/2026.
 */
class GoalHistoryTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuario->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->ana);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function objetivo(array $campos = []): Goal
    {
        return Goal::create(array_merge([
            'name' => 'Reserva', 'priority' => 1, 'estimated_value' => '10000.00', 'current_amount' => '1000.00', 'funding_method' => FundingMethod::LumpSum,
            'status' => GoalStatus::Active, 'installment_amount' => '600.00',
        ], $campos));
    }

    private function aporte(Goal $g, string $valor, string $data, ?string $nota = null): GoalContribution
    {
        return GoalContribution::create(['profile_id' => $g->profile_id, 'goal_id' => $g->id, 'member_id' => $g->member_id, 'is_private' => (bool) $g->is_private, 'amount' => $valor, 'contributed_on' => $data, 'note' => $nota]);
    }

    private function historico(Goal $g)
    {
        return Livewire::test(GoalHistory::class)->dispatch('open-goal-history', id: $g->id);
    }

    // ------------------------------------------------------------------ o progresso passa a somar os aportes

    public function test_o_objetivo_tem_o_saldo_inicial_mais_os_aportes_e_a_previsao_acompanha(): void
    {
        $g = $this->objetivo();
        self::assertSame('1000.00', $g->accumulated());
        self::assertSame(15, $g->forecast()['months'], 'faltam 9.000 a 600 por mês');

        $this->aporte($g, '1500.00', '2026-10-05');

        $g = Goal::query()->findOrFail($g->id);
        self::assertSame('2500.00', $g->accumulated());
        self::assertSame(13, $g->forecast()['months'], 'faltam 7.500 a 600 por mês');
        self::assertSame(25.0, $g->progressPercentage());
    }

    public function test_a_soma_em_sql_da_lista_nao_faz_uma_consulta_por_objetivo(): void
    {
        foreach (range(1, 5) as $i) {
            $this->aporte($this->objetivo(['name' => "O{$i}", 'priority' => $i]), '100.00', '2026-11-01');
        }

        DB::enableQueryLog();
        $objetivos = Goal::query()->active()->withContributionTotal()->get();
        $objetivos->each->accumulated();
        $consultas = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'goal_contributions'))->count();
        DB::disableQueryLog();

        self::assertSame(1, $consultas, 'uma só consulta traz a soma de todos');
    }

    public function test_o_painel_e_a_lista_somam_os_aportes(): void
    {
        $g = $this->objetivo();
        $this->aporte($g, '1500.00', '2026-10-05');

        self::assertSame('2500.00', app(DashboardService::class)->goalsSummary()['acumulado']);
        Livewire::test(GoalsIndex::class)->assertSee('R$ 2.500,00');
    }

    public function test_a_retirada_diminui_e_o_progresso_nunca_fica_abaixo_de_zero(): void
    {
        $g = $this->objetivo(['current_amount' => '0.00']);
        $this->aporte($g, '300.00', '2026-10-05');
        // Um lançamento direto (sem passar pela validação da tela) que passaria de zero: o progresso é limitado a zero.
        $this->aporte($g, '-900.00', '2026-10-06');

        self::assertSame('0.00', Goal::query()->findOrFail($g->id)->accumulated());
    }

    // ------------------------------------------------------------------ registrar

    public function test_registrar_um_aporte_grava_com_dono_e_privacidade_do_objetivo_e_avisa_a_lista(): void
    {
        $g = $this->objetivo(['member_id' => $this->ana->id, 'is_private' => true]);

        $this->historico($g)->assertSee('Aportes: Reserva')->assertSee('Já tem R$ 1.000,00 de R$ 10.000,00 (10,0%).')
            ->set('amount', '500,00')->set('date', '2026-11-10')->set('note', '13º salário')
            ->call('save')->assertHasNoErrors()->assertDispatched('goals-changed')->assertSee('Aporte registrado.')->assertSee('13º salário')
            ->assertSee('Já tem R$ 1.500,00 de R$ 10.000,00 (15,0%).');

        $l = GoalContribution::query()->sole();
        self::assertSame('500.00', $l->amount);
        self::assertSame('2026-11-10', $l->contributed_on->toDateString());
        self::assertSame($this->ana->id, $l->member_id);
        self::assertTrue($l->is_private);

        // A observação fica cifrada no banco.
        self::assertStringNotContainsString('13º', (string) DB::table('goal_contributions')->where('id', $l->id)->value('note'));
    }

    public function test_registrar_uma_retirada_grava_valor_negativo_e_nao_passa_do_que_o_objetivo_tem(): void
    {
        $g = $this->objetivo();

        $this->historico($g)->set('type', 'retirada')->set('amount', '400,00')->call('save')->assertHasNoErrors()->assertSee('Retirada registrada.');
        self::assertSame('-400.00', GoalContribution::query()->sole()->amount);

        // Sobram 600: tirar 700 deixaria abaixo de zero.
        $this->historico($g)->set('type', 'retirada')->set('amount', '700,00')->call('save')
            ->assertHasErrors('amount')->assertSee('A retirada é maior que o que o objetivo tem hoje (R$ 600,00).');
        self::assertSame(1, GoalContribution::query()->count());
    }

    public function test_validacoes_do_lancamento(): void
    {
        $g = $this->objetivo();

        $this->historico($g)->set('amount', '')->call('save')->assertHasErrors('amount');
        $this->historico($g)->set('amount', 'muito')->call('save')->assertHasErrors('amount');
        $this->historico($g)->set('amount', '0,00')->call('save')->assertHasErrors('amount');
        $this->historico($g)->set('amount', '100,00')->set('date', '2026-12-31')->call('save')->assertHasErrors('date');
        $this->historico($g)->set('amount', '100,00')->set('type', 'doacao')->call('save')->assertHasErrors('type');
        self::assertSame(0, GoalContribution::query()->count());
    }

    // ------------------------------------------------------------------ edição e exclusão

    public function test_editar_muda_o_lancamento_e_a_regra_da_retirada_ignora_o_proprio_lancamento(): void
    {
        $g = $this->objetivo(['current_amount' => '0.00']);
        $a = $this->aporte($g, '500.00', '2026-10-05');

        // Trocar o aporte de 500 por uma retirada de 100 deixaria o objetivo em −100 sem ele: recusa.
        $this->historico($g)->call('edit', $a->id)->assertSet('amount', '500.00')->set('type', 'retirada')->set('amount', '100,00')->call('save')
            ->assertHasErrors('amount');
        self::assertSame('500.00', $a->fresh()->amount);

        $this->historico($g)->call('edit', $a->id)->set('amount', '650,00')->set('note', 'ajustado')->call('save')->assertHasNoErrors()->assertSee('Lançamento atualizado.');
        self::assertSame('650.00', $a->fresh()->amount);
        self::assertSame('ajustado', $a->fresh()->note);
    }

    public function test_excluir_remove_o_lancamento_e_o_progresso_volta(): void
    {
        $g = $this->objetivo();
        $a = $this->aporte($g, '500.00', '2026-10-05');

        $this->historico($g)->call('delete', $a->id)->assertDispatched('goals-changed');

        self::assertSame(0, GoalContribution::query()->count());
        self::assertSame('1000.00', Goal::query()->findOrFail($g->id)->accumulated());
    }

    // ------------------------------------------------------------------ linha do tempo e ritmo

    public function test_a_linha_do_tempo_mostra_o_total_do_mes_o_acumulado_e_o_percentual(): void
    {
        $g = $this->objetivo(); // saldo inicial 1.000; objetivo 10.000
        $this->aporte($g, '500.00', '2026-09-10');
        $this->aporte($g, '700.00', '2026-10-03');
        $this->aporte($g, '-200.00', '2026-10-20');
        $this->aporte($g, '300.00', '2026-11-02');

        $html = $this->historico($g)->html();
        $ultimo = -1;

        // Do mês mais recente para o mais antigo, cada um com o total do mês, o acumulado e o percentual.
        foreach (['11/2026', '+R$ 300,00', 'R$ 2.300,00 (23%)', '10/2026', '+R$ 500,00', 'R$ 2.000,00 (20%)', '09/2026', '+R$ 500,00', 'R$ 1.500,00 (15%)'] as $trecho) {
            $pos = strpos($html, $trecho, $ultimo + 1);
            self::assertNotFalse($pos, "não achei (na ordem): {$trecho}");
            $ultimo = $pos;
        }
    }

    public function test_o_ritmo_real_e_a_media_dos_ultimos_tres_meses_contra_o_planejado(): void
    {
        $g = $this->objetivo();
        $this->aporte($g, '600.00', '2026-09-10');   // dentro da janela (set, out, nov)
        $this->aporte($g, '600.00', '2026-11-02');
        $this->aporte($g, '9000.00', '2026-08-31');  // fora da janela

        $this->historico($g)->assertSee('Ritmo real: média de')->assertSee('R$ 400,00')->assertSee('por mês nos últimos 3 meses')->assertSee('(planejado: R$ 600,00 por mês).');
    }

    public function test_sem_aporte_planejado_diz_que_ainda_nao_informou(): void
    {
        $this->historico($this->objetivo(['installment_amount' => null]))->assertSee('você ainda não informou quanto planeja guardar por mês');
    }

    public function test_sem_lancamento_mostra_o_convite_para_registrar(): void
    {
        $this->historico($this->objetivo())->assertSee('Nenhum aporte registrado ainda.');
    }

    // ------------------------------------------------------------------ onde vale e isolamento

    public function test_objetivo_ligado_a_investimento_nao_tem_historico_nem_botao(): void
    {
        $investimento = InvestmentRecord::factory()->for($this->perfil, 'profile')->create(['current_amount' => '3000.00', 'name' => 'CDB Reserva']);
        $g = $this->objetivo(['linked_investment_id' => $investimento->id, 'current_amount' => '0.00', 'name' => 'Ligado']);

        self::assertFalse($g->acceptsContributions());
        Livewire::test(GoalHistory::class)->dispatch('open-goal-history', id: $g->id)->assertNotFound();
        Livewire::test(GoalsIndex::class)->assertSee('Ligado')->assertDontSeeHtml('data-open-goal-history');
    }

    public function test_a_lista_mostra_o_botao_aportes_para_o_objetivo_sem_ligacao(): void
    {
        $this->objetivo();

        Livewire::test(GoalsIndex::class)->assertSeeHtml('data-open-goal-history');
    }

    public function test_lancamento_de_outro_objetivo_ou_perfil_da_404(): void
    {
        $g = $this->objetivo();
        $outro = $this->objetivo(['name' => 'Outro', 'priority' => 2]);
        $dele = $this->aporte($outro, '100.00', '2026-11-01');

        $this->historico($g)->call('edit', $dele->id)->assertNotFound();
        $this->historico($g)->call('delete', $dele->id)->assertNotFound();
        self::assertNotNull($dele->fresh());

        $alheio = FinancialProfile::factory()->create();
        $objetivoAlheio = Goal::withoutGlobalScopes()->create(['profile_id' => $alheio->id, 'name' => 'Alheio', 'priority' => 1, 'estimated_value' => '1000.00', 'current_amount' => '0.00', 'funding_method' => FundingMethod::LumpSum, 'status' => GoalStatus::Active]);
        Livewire::test(GoalHistory::class)->dispatch('open-goal-history', id: $objetivoAlheio->id)->assertNotFound();
    }

    public function test_o_conjuge_nao_ve_os_aportes_de_um_objetivo_oculto(): void
    {
        $bruno = User::factory()->create();
        $membroBruno = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $bruno->id, 'name' => 'Bruno']);
        $g = $this->objetivo(['member_id' => $this->ana->id, 'is_private' => true]);
        $this->aporte($g, '500.00', '2026-11-01');

        $this->actingAs($bruno);
        app(ProfileContext::class)->set($this->perfil, $membroBruno);

        self::assertSame(0, GoalContribution::query()->count());
        Livewire::test(GoalHistory::class)->dispatch('open-goal-history', id: $g->id)->assertNotFound();
    }

    public function test_mudar_o_dono_ou_ocultar_o_objetivo_leva_os_aportes_junto(): void
    {
        $g = $this->objetivo(['member_id' => $this->ana->id]);
        $this->aporte($g, '500.00', '2026-11-01');
        self::assertFalse(GoalContribution::query()->sole()->is_private);

        Livewire::test(GoalsIndex::class)->call('edit', $g->id)->set('memberId', $this->ana->id)->set('isPrivate', true)->call('save')->assertHasNoErrors();

        self::assertTrue(Goal::query()->findOrFail($g->id)->is_private);
        self::assertTrue(GoalContribution::withoutGlobalScopes()->sole()->is_private);
    }

    public function test_apagar_o_objetivo_leva_os_aportes_junto(): void
    {
        $g = $this->objetivo();
        $this->aporte($g, '500.00', '2026-11-01');

        $g->delete();

        self::assertSame(0, DB::table('goal_contributions')->count());
    }
}
