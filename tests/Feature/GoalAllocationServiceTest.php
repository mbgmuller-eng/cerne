<?php

namespace Tests\Feature;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Models\FinancialProfile;
use App\Models\Goal;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\GoalAllocationService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A divisão do balde "Objetivos" entre os objetivos: por prioridade ou proporcional, a sobra para os sem data-alvo, quem fica de fora e o que é
 * gravado ao aplicar. Hoje é 12/11/2026.
 */
class GoalAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private ProfileMember $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuario->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->bruno = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id, 'role' => MemberRole::Secondary, 'name' => 'Bruno']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->ana);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Objetivo com data-alvo daqui a $meses meses: precisa de (valor / meses) por mês. */
    private function objetivo(string $nome, int $prioridade, string $valor, ?int $meses, array $campos = []): Goal
    {
        return Goal::create(array_merge([
            'name' => $nome, 'priority' => $prioridade, 'estimated_value' => $valor, 'current_amount' => '0.00', 'funding_method' => FundingMethod::LumpSum,
            'status' => GoalStatus::Active, 'created_by_user_id' => auth()->id(),
            'target_date' => $meses !== null ? CarbonImmutable::now()->addMonths($meses)->toDateString() : null,
        ], $campos));
    }

    private function lista(): array
    {
        return Goal::query()->active()->byPriority()->withContributionTotal()->withInvestmentCount()->get()->all();
    }

    private function sugerido(array $r, string $nome): string
    {
        return collect($r['rows'])->first(fn ($l) => $l['goal']->name === $nome)['suggested'];
    }

    public function test_por_prioridade_cada_um_recebe_o_necessario_ate_o_dinheiro_acabar(): void
    {
        $this->objetivo('Reserva', 1, '12000.00', 10);       // precisa 1.200 por mês
        $this->objetivo('Apartamento', 2, '25000.00', 10);   // precisa 2.500 por mês
        $this->objetivo('Viagem', 3, '8000.00', 10);         // precisa 800 por mês

        $r = app(GoalAllocationService::class)->allocate('3000.00', $this->lista(), 'priority');

        self::assertSame('1200.00', $this->sugerido($r, 'Reserva'));
        self::assertSame('1800.00', $this->sugerido($r, 'Apartamento'));
        self::assertSame('0.00', $this->sugerido($r, 'Viagem'));
        self::assertSame('3000.00', $r['allocated']);
        self::assertSame('0.00', $r['leftover']);

        $porNome = collect($r['rows'])->keyBy(fn ($l) => $l['goal']->name);
        self::assertSame('full', $porNome['Reserva']['status']);
        self::assertSame('partial', $porNome['Apartamento']['status']);
        self::assertSame('none', $porNome['Viagem']['status']);
        self::assertNotNull($porNome['Apartamento']['forecast'], 'diz quando o objetivo chega com o valor sugerido');
        self::assertNull($porNome['Viagem']['forecast']);
    }

    public function test_proporcional_reparte_pelo_que_cada_um_precisa(): void
    {
        $this->objetivo('Reserva', 1, '12000.00', 10);
        $this->objetivo('Apartamento', 2, '25000.00', 10);
        $this->objetivo('Viagem', 3, '8000.00', 10);

        $r = app(GoalAllocationService::class)->allocate('3000.00', $this->lista(), 'proportional');

        self::assertSame('800.00', $this->sugerido($r, 'Reserva'));      // 3000 x 1200 / 4500
        self::assertSame('1666.67', $this->sugerido($r, 'Apartamento')); // 3000 x 2500 / 4500
        self::assertSame('533.33', $this->sugerido($r, 'Viagem'));
        self::assertLessThanOrEqual(0.01, abs((float) $r['allocated'] - 3000.0));
    }

    public function test_dinheiro_a_mais_nao_passa_do_necessario_e_a_sobra_vai_para_os_sem_data(): void
    {
        $this->objetivo('Reserva', 1, '12000.00', 10);          // 1.200
        $this->objetivo('Sem data A', 2, '5000.00', null);
        $this->objetivo('Sem data B', 3, '5000.00', null);

        $r = app(GoalAllocationService::class)->allocate('2001.00', $this->lista(), 'priority');

        self::assertSame('1200.00', $this->sugerido($r, 'Reserva'), 'nunca mais que o necessário');
        self::assertSame('400.50', $this->sugerido($r, 'Sem data A'));
        self::assertSame('400.50', $this->sugerido($r, 'Sem data B'));
        self::assertSame('0.00', $r['leftover']);
        self::assertSame('leftover', collect($r['rows'])->first(fn ($l) => $l['goal']->name === 'Sem data A')['status']);
    }

    public function test_a_sobra_sem_objetivo_sem_data_fica_como_sobra(): void
    {
        $this->objetivo('Reserva', 1, '12000.00', 10);

        $r = app(GoalAllocationService::class)->allocate('2000.00', $this->lista(), 'priority');

        self::assertSame('1200.00', $this->sugerido($r, 'Reserva'));
        self::assertSame('800.00', $r['leftover']);
    }

    public function test_os_centavos_que_nao_dividem_exato_nao_se_perdem_entre_os_sem_data(): void
    {
        $this->objetivo('A', 1, '5000.00', null);
        $this->objetivo('B', 2, '5000.00', null);
        $this->objetivo('C', 3, '5000.00', null);

        $r = app(GoalAllocationService::class)->allocate('100.00', $this->lista());

        self::assertSame('100.00', $r['allocated']);
    }

    public function test_alcancado_conquistado_e_ligado_a_consorcio_ficam_de_fora(): void
    {
        $this->objetivo('Em andamento', 1, '12000.00', 10);
        $this->objetivo('Já alcançado', 2, '1000.00', 10, ['current_amount' => '1000.00']);
        $this->objetivo('Conquistado', 3, '1000.00', 10, ['status' => GoalStatus::Achieved]);
        $this->objetivo('Do consórcio', 4, '50000.00', 10, ['linked_debt_id' => \App\Models\Debt::withoutGlobalScopes()->create([
            'profile_id' => $this->perfil->id, 'kind' => \App\Enums\DebtKind::Consortium, 'status' => \App\Enums\DebtStatus::Active, 'name' => 'Cons', 'creditor' => 'X',
            'consortium_type' => \App\Enums\ConsortiumType::Property, 'credit_amount' => '50000.00', 'term' => 60, 'first_due_date' => '2026-12-15', 'installment_amount' => '900.00',
        ])->id, 'funding_method' => FundingMethod::Installments]);

        $r = app(GoalAllocationService::class)->allocate('5000.00', $this->lista());

        self::assertCount(1, $r['rows']);
        self::assertSame('Em andamento', $r['rows'][0]['goal']->name);
    }

    public function test_aplicar_grava_o_quanto_vou_guardar_por_mes_so_do_que_a_pessoa_pode_editar(): void
    {
        $meu = $this->objetivo('Meu', 1, '12000.00', 10);
        $doBruno = $this->objetivo('Do Bruno', 2, '12000.00', 10, ['member_id' => $this->bruno->id, 'is_private' => false]);

        $gravados = app(GoalAllocationService::class)->apply([$meu->id => '1200.00', $doBruno->id => '700.00', 'inventado' => '10.00', $meu->id.'x' => '5.00']);

        self::assertSame(1, $gravados, 'o objetivo de outro membro e o id inventado não são gravados');
        self::assertSame('1200.00', (string) $meu->fresh()->installment_amount);
        self::assertNull($doBruno->fresh()->installment_amount);
    }

    public function test_aplicar_valor_zero_ou_negativo_nao_grava(): void
    {
        $g = $this->objetivo('Meu', 1, '12000.00', 10);

        self::assertSame(0, app(GoalAllocationService::class)->apply([$g->id => '0.00']));
        self::assertNull($g->fresh()->installment_amount);
    }
}
