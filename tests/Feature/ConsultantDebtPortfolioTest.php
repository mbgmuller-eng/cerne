<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Livewire\Consultant\PortfolioDebts;
use App\Models\ConsultantClient;
use App\Models\Debt;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ConsultantPortfolioService;
use App\Services\DebtService;
use App\Support\PrivacyGovernedModels;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dívidas na carteira do consultor: só dos clientes com vínculo ativo, com o que é privado entre o casal, sem misturar clientes.
 */
class ConsultantDebtPortfolioTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private User $clienteA;

    private FinancialProfile $perfilA;

    private ProfileMember $membroA;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));
        $this->seed(TaxonomySeeder::class);

        $this->consultor = User::factory()->consultant()->create();
        [$this->clienteA, $this->perfilA, $this->membroA] = $this->cliente('Ana Silva');
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->clienteA->id, 'status' => ConsultantClientStatus::Active]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileMember} */
    private function cliente(string $nome): array
    {
        $user = User::factory()->create(['name' => $nome]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $user->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $user->id, 'role' => MemberRole::Primary, 'name' => $nome]);

        return [$user, $perfil, $membro];
    }

    private function como(User $user, FinancialProfile $perfil, ?ProfileMember $membro): void
    {
        $this->actingAs($user);
        app(ProfileContext::class)->set($perfil, $membro);
    }

    private function emprestimo(string $nome, array $campos = []): Debt
    {
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();

        return app(DebtService::class)->create(array_merge([
            'kind' => 'loan', 'name' => $nome, 'creditor' => 'Banco X', 'amortization' => 'fixed', 'installment_amount' => '1000.00', 'term' => 6,
            'first_due_date' => '2026-10-25', 'paid_before' => 0, 'category_id' => $cat->id,
        ], $campos));
    }

    private function visao(): array
    {
        $this->actingAs($this->consultor);
        app(ProfileContext::class)->set($this->perfilA, null, true);

        return app(ConsultantPortfolioService::class)->debtOverview($this->consultor);
    }

    public function test_soma_o_devedor_e_as_parcelas_do_mes_por_cliente(): void
    {
        $this->como($this->clienteA, $this->perfilA, $this->membroA);
        $this->emprestimo('Empréstimo A');                                                               // 6 x 1.000, a 1ª em outubro
        app(DebtService::class)->createOpenDebt(['name' => 'Cartão atrasado', 'current_balance' => '4250.00']);

        $visao = $this->visao();

        self::assertCount(1, $visao['clients']);
        $c = $visao['clients']->first();
        self::assertSame('Ana Silva', $c['client_name']);
        self::assertSame('10250.00', $c['balance'], '6.000 do empréstimo + 4.250 do cartão');
        self::assertSame('1000.00', $c['month']);
        self::assertSame('10250.00', $visao['totals']['balance']);
        self::assertSame(1, $visao['totals']['clients_with_debt']);
        self::assertCount(2, $c['rows']);
    }

    public function test_consorcio_nao_contemplado_entra_como_carta_e_nao_como_divida(): void
    {
        $this->como($this->clienteA, $this->perfilA, $this->membroA);
        app(DebtService::class)->createConsortium([
            'consortium_type' => 'property', 'credit_amount' => '100000.00', 'installment_amount' => '1000.00', 'term' => 100, 'first_due_date' => '2026-11-10',
        ]);

        $visao = $this->visao();

        self::assertSame('0.00', $visao['totals']['balance']);
        self::assertSame('100000.00', $visao['totals']['credit']);
        self::assertSame(0, $visao['totals']['clients_with_debt']);
    }

    public function test_o_consultor_ve_a_divida_privada_do_casal_mas_so_de_clientes_vinculados(): void
    {
        $this->como($this->clienteA, $this->perfilA, $this->membroA);
        $this->emprestimo('Privada', ['member_id' => $this->membroA->id, 'is_private' => true]);

        [$outro, $perfilB, $membroB] = $this->cliente('Cliente sem vínculo');
        $this->como($outro, $perfilB, $membroB);
        $this->emprestimo('De outro cliente');

        $visao = $this->visao();

        self::assertSame(['Ana Silva'], $visao['clients']->keys()->all());
        self::assertSame(['Privada'], collect($visao['clients']->first()['rows'])->map(fn (array $r) => $r['debt']->name)->all());
    }

    public function test_vinculo_encerrado_tira_o_cliente_da_visao(): void
    {
        $this->como($this->clienteA, $this->perfilA, $this->membroA);
        $this->emprestimo('Empréstimo A');
        ConsultantClient::query()->where('consultant_id', $this->consultor->id)->update(['status' => ConsultantClientStatus::Inactive]);

        self::assertCount(0, $this->visao()['clients']);
    }

    public function test_tela_mostra_os_totais_e_o_cliente(): void
    {
        $this->como($this->clienteA, $this->perfilA, $this->membroA);
        $this->emprestimo('Empréstimo A');
        $this->actingAs($this->consultor);
        app(ProfileContext::class)->set($this->perfilA, null, true);

        Livewire::test(PortfolioDebts::class)
            ->assertSee('Dívidas da carteira')->assertSee('Ana Silva')->assertSee('Empréstimo A')->assertSee('Total devedor da carteira');
    }

    public function test_tela_vazia_sem_dividas(): void
    {
        $this->actingAs($this->consultor);

        Livewire::test(PortfolioDebts::class)->assertSee('Nenhum cliente vinculado tem dívida');
    }

    public function test_cliente_comum_nao_entra_na_tela_do_consultor(): void
    {
        $this->actingAs($this->clienteA);

        $this->get(route('consultant.portfolio.debts'))->assertForbidden();
    }

    public function test_a_privacidade_das_dividas_conta_para_saber_se_ha_algo_oculto(): void
    {
        $this->como($this->clienteA, $this->perfilA, $this->membroA);
        self::assertFalse(PrivacyGovernedModels::anyPrivate($this->perfilA->id, [Debt::class]));

        $this->emprestimo('Privada', ['member_id' => $this->membroA->id, 'is_private' => true]);

        self::assertTrue(PrivacyGovernedModels::anyPrivate($this->perfilA->id, [Debt::class]));
    }
}
