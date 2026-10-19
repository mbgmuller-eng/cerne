<?php

namespace Tests\Feature;

use App\Enums\DebtInstallmentStatus;
use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Livewire\Debts\DebtShow;
use App\Livewire\Debts\DebtsIndex;
use App\Livewire\Debts\DebtTaxReport;
use App\Models\Debt;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Services\DebtTaxReportService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dívidas e ônus reais do imposto de renda: saldo em 31/12 por dívida, com o credor e o CPF ou CNPJ dele.
 */
class DebtTaxReportTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-02-10 09:00:00'));
        $this->seed(TaxonomySeeder::class);

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'role' => MemberRole::Primary, 'name' => 'Marcelo']);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function servico(): DebtService
    {
        return app(DebtService::class);
    }

    private function relatorio(int $ano, ?string $pessoa = null): array
    {
        return app(DebtTaxReportService::class)->forYear($ano, $pessoa);
    }

    private function classificacao(): array
    {
        $cat = ExpenseCategory::available()->where('name', 'Financeiros')->firstOrFail();

        return ['category_id' => $cat->id, 'subcategory_id' => $cat->subcategories()->where('name', 'Empréstimo')->firstOrFail()->id];
    }

    /** Empréstimo de parcelas fixas: 6 x 1.000 a partir de 05/11/2026, contratado em 01/10/2026. */
    private function emprestimo(array $campos = []): Debt
    {
        return $this->servico()->create(array_merge([
            'kind' => 'loan', 'name' => 'Empréstimo', 'creditor' => 'Banco X', 'creditor_document' => '00.000.000/0001-00', 'amortization' => 'fixed',
            'installment_amount' => '1000.00', 'term' => 6, 'first_due_date' => '2026-11-05', 'contracted_on' => '2026-10-01', 'paid_before' => 0,
        ], $this->classificacao(), $campos));
    }

    public function test_saldo_em_31_12_conta_o_que_ainda_nao_foi_pago_na_data(): void
    {
        $divida = $this->emprestimo();
        $this->servico()->pay($divida->installments[0], CarbonImmutable::parse('2026-11-05'));   // 1ª paga em novembro
        $this->servico()->pay($divida->installments[1], CarbonImmutable::parse('2027-01-10'));   // 2ª paga só em janeiro

        $r = $this->relatorio(2026);

        self::assertCount(1, $r['rows']);
        self::assertSame('0.00', $r['rows'][0]['previous'], 'contratado em outubro de 2026');
        self::assertSame('5000.00', $r['rows'][0]['current'], '6.000 menos a 1ª, paga antes de 31/12; a 2ª ainda era devida');
        self::assertSame('Banco X', $r['rows'][0]['creditor']);
        self::assertSame('00.000.000/0001-00', $r['rows'][0]['document']);

        $seguinte = $this->relatorio(2027);
        self::assertSame('5000.00', $seguinte['rows'][0]['previous']);
        self::assertSame('5000.00', $seguinte['total_previous']);
    }

    public function test_financiamento_com_cronograma_usa_a_amortizacao_e_nao_os_juros(): void
    {
        $this->servico()->create(array_merge([
            'kind' => 'financing', 'name' => 'Apartamento', 'amortization' => 'sac', 'principal' => '120000.00', 'rate' => '12', 'rate_basis' => 'annual_nominal',
            'term' => 12, 'first_due_date' => '2026-11-05', 'contracted_on' => '2026-10-20', 'paid_before' => 0,
        ], $this->classificacao()));

        self::assertSame('120000.00', $this->relatorio(2026)['rows'][0]['current']);
    }

    public function test_sem_data_de_contratacao_estima_pelo_primeiro_vencimento_e_diz_que_e_estimativa(): void
    {
        // contratada em dezembro, 1ª parcela em fevereiro: estimar "um mês antes" deixaria de fora o saldo de 31/12
        $this->emprestimo(['name' => 'Sem data', 'first_due_date' => '2027-02-05', 'contracted_on' => null]);
        $this->emprestimo(['name' => 'Com data', 'first_due_date' => '2027-02-05', 'contracted_on' => '2026-12-20']);

        $linhas = collect($this->relatorio(2026)['rows'])->keyBy(fn (array $l) => $l['debt']->name);

        self::assertArrayNotHasKey('Sem data', $linhas->all());
        self::assertSame('6000.00', $linhas['Com data']['current']);
        self::assertFalse($linhas['Com data']['contract_estimated']);
    }

    public function test_parcela_pulada_nao_entra_no_saldo(): void
    {
        $divida = $this->emprestimo();
        $this->servico()->markDiluted($divida->installments[2]);

        self::assertSame('5000.00', $this->relatorio(2026)['rows'][0]['current']);
    }

    public function test_consorcio_so_entra_depois_de_contemplado(): void
    {
        $consorcio = $this->servico()->createConsortium([
            'consortium_type' => 'property', 'credit_amount' => '100000.00', 'installment_amount' => '1000.00', 'term' => 100, 'first_due_date' => '2026-01-10', 'paid_before' => 0,
        ]);

        self::assertSame([], $this->relatorio(2026)['rows']);

        $this->servico()->contemplate($consorcio, [
            'contemplated_on' => '2026-06-15', 'mode' => 'draw', 'new_installment_amount' => '700.00', 'remaining_installments' => 60,
            'category_id' => ExpenseCategory::available()->where('name', 'Habitação')->firstOrFail()->id,
        ]);

        $r = $this->relatorio(2026);
        self::assertCount(1, $r['rows']);
        self::assertSame('42000.00', $r['rows'][0]['current']);
        self::assertSame('0.00', $r['rows'][0]['previous'], 'contemplado só em 2026');
    }

    public function test_filtra_por_pessoa_e_mantem_a_do_casal(): void
    {
        $outra = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'role' => MemberRole::Secondary, 'name' => 'Ana']);
        $this->emprestimo(['name' => 'Do Marcelo', 'member_id' => $this->membro->id]);
        $this->emprestimo(['name' => 'Da Ana', 'member_id' => $outra->id]);
        $this->emprestimo(['name' => 'Do casal']);

        $nomes = collect($this->relatorio(2026, $this->membro->id)['rows'])->map(fn (array $l) => $l['debt']->name)->sort()->values()->all();

        self::assertSame(['Do Marcelo', 'Do casal'], $nomes);
        self::assertCount(3, $this->relatorio(2026)['rows']);
    }

    public function test_divida_sem_cronograma_aparece_a_parte_e_fora_dos_totais(): void
    {
        $this->servico()->createOpenDebt(['name' => 'Cartão atrasado', 'creditor' => 'Banco Y', 'creditor_document' => '11.111.111/0001-11', 'current_balance' => '4250.00']);
        $this->emprestimo();

        $r = $this->relatorio(2026);

        self::assertCount(1, $r['unscheduled']);
        self::assertSame('4250.00', $r['unscheduled'][0]['balance']);
        self::assertSame('6000.00', $r['total_current'], 'o cartão não soma');
    }

    public function test_sem_dividas_o_relatorio_vem_vazio(): void
    {
        self::assertFalse($this->relatorio(2026)['has_data']);
    }

    // ------------------------------------------------------------------ tela

    public function test_tela_mostra_o_ano_o_credor_e_avisa_quando_falta_o_documento(): void
    {
        $this->emprestimo();
        $this->emprestimo(['name' => 'Sem documento', 'creditor_document' => null]);

        Livewire::test(DebtTaxReport::class)
            ->assertSet('ano', '2026')   // a declaração de agora é a do ano anterior (fevereiro de 2027)
            ->assertSee('Dívidas e ônus reais')->assertSee('Banco X')->assertSee('00.000.000/0001-00')->assertSee('sem CPF ou CNPJ cadastrado');
    }

    public function test_tela_baixa_csv_e_pdf(): void
    {
        $this->emprestimo();

        Livewire::test(DebtTaxReport::class)->call('baixarCsv')->assertFileDownloaded('dividas-imposto-de-renda-2026.csv');
        Livewire::test(DebtTaxReport::class)->call('baixarPdf')->assertFileDownloaded('dividas-imposto-de-renda-2026.pdf');
    }

    public function test_a_rota_do_relatorio_nao_e_confundida_com_um_contrato(): void
    {
        $this->get(route('debts.tax'))->assertOk()->assertSee('Dívidas e ônus reais');
    }

    public function test_corretor_nao_entra(): void
    {
        $corretor = User::factory()->create(['role' => UserRole::Broker]);
        $this->actingAs($corretor);

        $this->get(route('debts.tax'))->assertForbidden();
    }

    public function test_cadastro_e_edicao_guardam_cpf_ou_cnpj_e_data_da_contratacao(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'loan')->set('name', 'Empréstimo novo')->set('creditor', 'Banco Z')->set('creditorDocument', '22.222.222/0001-22')->set('contractedOn', '2026-12-01')
            ->set('amortization', 'fixed')->set('installmentAmount', '500,00')->set('term', '6')->set('firstDueDate', '2027-01-10')
            ->call('save')->assertHasNoErrors();

        $divida = Debt::query()->sole();
        self::assertSame('22.222.222/0001-22', $divida->creditor_document);
        self::assertSame('2026-12-01', $divida->contracted_on->toDateString());

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openEdit')->assertSet('editCreditorDocument', '22.222.222/0001-22')
            ->set('editCreditorDocument', '33.333.333/0001-33')->call('saveEdit')->assertHasNoErrors();

        self::assertSame('33.333.333/0001-33', $divida->fresh()->creditor_document);
    }

    public function test_dividas_de_outro_perfil_nao_aparecem(): void
    {
        $this->emprestimo();

        $outro = User::factory()->create();
        $perfilB = FinancialProfile::factory()->create(['owner_user_id' => $outro->id]);
        $membroB = ProfileMember::factory()->create(['profile_id' => $perfilB->id, 'user_id' => $outro->id, 'role' => MemberRole::Primary]);
        $this->actingAs($outro);
        app(ProfileContext::class)->set($perfilB, $membroB);

        self::assertFalse($this->relatorio(2026)['has_data']);
        self::assertSame(DebtInstallmentStatus::Open, DebtInstallmentStatus::from('open'));
    }
}
