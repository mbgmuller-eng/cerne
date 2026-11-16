<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Models\BudgetRule;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\BudgetInvestmentGap;
use App\Services\BudgetGapNotifier;
use App\Support\Budget\BudgetStructure;
use App\Support\NotificationPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O aviso "faltam R$ X para a meta de investimentos": a partir do dia 20, uma vez por mês, com o número que CADA pessoa enxerga (o privado do casal não
 * vaza para o outro), pela rotina agendada de verdade. Hoje é 25/11/2026.
 */
class BudgetGapNotifierTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private User $usuarioAna;

    private User $usuarioBruno;

    private ProfileMember $ana;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-25 09:00:00'));

        $this->usuarioAna = User::factory()->create(['name' => 'Ana']);
        $this->usuarioBruno = User::factory()->create(['name' => 'Bruno']);
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioAna->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioAna->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioBruno->id, 'role' => MemberRole::Secondary, 'name' => 'Bruno']);

        ExpenseCategory::factory()->shared()->create(['name' => 'Ações', 'necessity' => Necessity::Investment]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function comRegraEReceita(string $receita = '10000.00'): void
    {
        BudgetRule::withoutProfileScope()->create(['profile_id' => $this->perfil->id, 'effective_year' => 2026, 'effective_month' => 1, 'structure' => BudgetStructure::normalize(BudgetStructure::default())]);
        IncomeRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => null, 'amount' => $receita, 'received_date' => '2026-11-05']);
    }

    private function investimento(string $valor, array $campos = []): void
    {
        ExpenseRecord::factory()->create(array_merge([
            'profile_id' => $this->perfil->id, 'member_id' => null, 'necessity' => 'investment', 'subcategory_id' => null, 'amount' => $valor, 'expense_date' => '2026-11-05',
            'category_id' => ExpenseCategory::withoutTaxonomyScope()->where('name', 'Ações')->value('id'),
        ], $campos));
    }

    public function test_antes_do_dia_20_nao_avisa(): void
    {
        Notification::fake();
        $this->comRegraEReceita();

        self::assertSame(0, app(BudgetGapNotifier::class)->notifyShortfalls(CarbonImmutable::parse('2026-11-19 09:00')));
        Notification::assertNothingSent();
    }

    public function test_cada_pessoa_recebe_o_numero_que_enxerga_e_so_quando_falta(): void
    {
        Notification::fake();
        $this->comRegraEReceita();
        $this->investimento('500.00');
        // Privado da Ana: ela soma (3.500 de 3.000, meta batida); o Bruno não enxerga, então para ele faltam 2.500.
        $this->investimento('3000.00', ['member_id' => $this->ana->id, 'is_private' => true]);

        self::assertSame(1, app(BudgetGapNotifier::class)->notifyShortfalls());

        Notification::assertNotSentTo($this->usuarioAna, BudgetInvestmentGap::class);
        Notification::assertSentTo($this->usuarioBruno, BudgetInvestmentGap::class, function (BudgetInvestmentGap $n) {
            self::assertStringContainsString('Faltam R$ 2.500,00 para a meta de investimentos de novembro', $n->message);
            self::assertStringContainsString('R$ 3.000,00, 30% da receita', $n->message);
            self::assertStringContainsString('Já foram R$ 500,00', $n->message);
            self::assertStringNotContainsString('3.500', $n->message, 'o privado da Ana não aparece para o Bruno');

            return $n->profileId === $this->perfil->id;
        });
    }

    public function test_a_rotina_repetida_no_mesmo_mes_nao_avisa_de_novo(): void
    {
        Notification::fake();
        $this->comRegraEReceita();
        $servico = app(BudgetGapNotifier::class);

        self::assertSame(2, $servico->notifyShortfalls());
        self::assertSame(0, $servico->notifyShortfalls());
        self::assertSame(0, $servico->notifyShortfalls(CarbonImmutable::parse('2026-11-30 09:00')));
        Notification::assertSentToTimes($this->usuarioAna, BudgetInvestmentGap::class, 1);
        Notification::assertSentToTimes($this->usuarioBruno, BudgetInvestmentGap::class, 1);
    }

    public function test_sem_receita_sem_regra_ou_com_a_meta_batida_nao_avisa(): void
    {
        Notification::fake();
        $servico = app(BudgetGapNotifier::class);

        self::assertSame(0, $servico->notifyShortfalls(), 'sem regra');

        BudgetRule::withoutProfileScope()->create(['profile_id' => $this->perfil->id, 'effective_year' => 2026, 'effective_month' => 1, 'structure' => BudgetStructure::normalize(BudgetStructure::default())]);
        self::assertSame(0, $servico->notifyShortfalls(), 'sem receita no mês a meta é zero');

        IncomeRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => null, 'amount' => '10000.00', 'received_date' => '2026-11-05']);
        $this->investimento('3000.00');
        self::assertSame(0, $servico->notifyShortfalls(), 'meta batida');
        Notification::assertNothingSent();
    }

    public function test_so_membro_com_login_recebe_e_o_consultor_nao(): void
    {
        Notification::fake();
        $this->comRegraEReceita();
        $consultor = User::factory()->consultant()->create();
        ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => null, 'role' => MemberRole::Secondary, 'name' => 'Sem login']);

        app(BudgetGapNotifier::class)->notifyShortfalls();

        Notification::assertNotSentTo($consultor, BudgetInvestmentGap::class);
        Notification::assertSentToTimes($this->usuarioAna, BudgetInvestmentGap::class, 1);
    }

    public function test_a_rotina_agendada_roda_de_verdade_e_avisa(): void
    {
        // Roda o fechamento registrado em routes/console.php, não só o serviço: nome de classe errado ali já derrubou outra rotina todo dia sem teste ver.
        Notification::fake();
        $this->comRegraEReceita();

        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'regra-de-orcamento');
        self::assertNotNull($evento, 'a rotina precisa estar agendada');
        self::assertSame('30 8 * * *', $evento->expression);

        $evento->run($this->app);

        Notification::assertSentTo($this->usuarioAna, BudgetInvestmentGap::class);
        Notification::assertSentTo($this->usuarioBruno, BudgetInvestmentGap::class);
    }

    public function test_o_aviso_aparece_na_central_com_titulo_texto_e_link(): void
    {
        $this->comRegraEReceita();
        $this->investimento('500.00');

        app(BudgetGapNotifier::class)->notifyShortfalls();

        $linha = $this->usuarioBruno->notifications()->first();
        self::assertSame('budget_investment_gap', $linha->data['kind']);
        self::assertSame('Meta de investimentos de novembro', $linha->data['title']);

        $apresentado = NotificationPresenter::present($linha);
        self::assertSame('Meta de investimentos de novembro', $apresentado['heading']);
        self::assertStringContainsString('Faltam R$ 2.500,00', $apresentado['message']);
        self::assertSame(route('budget.index'), $apresentado['url']);
    }
}
