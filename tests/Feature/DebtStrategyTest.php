<?php

namespace Tests\Feature;

use App\Enums\DebtAmortization;
use App\Enums\DebtRateBasis;
use App\Enums\MemberRole;
use App\Livewire\Debts\DebtShow;
use App\Livewire\Debts\DebtsIndex;
use App\Models\Debt;
use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\DebtService;
use App\Services\DebtStrategyService;
use App\Support\Debts\DebtScheduleCalculator;
use App\Support\Debts\DebtSimulator;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Dívidas, fase 3: o que ajuda a decidir (qual quitar primeiro, simulador de antecipação e portabilidade, projeção do saldo).
 */
class DebtStrategyTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 09:00:00'));
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

    private function estrategia(): DebtStrategyService
    {
        return app(DebtStrategyService::class);
    }

    private function classificacao(string $categoria = 'Financeiros', string $sub = 'Empréstimo'): array
    {
        $cat = ExpenseCategory::available()->where('name', $categoria)->firstOrFail();

        return ['category_id' => $cat->id, 'subcategory_id' => $cat->subcategories()->where('name', $sub)->firstOrFail()->id];
    }

    /** Financiamento com juros: 12% ao ano nominal = 1% ao mês. */
    private function financiamento(array $campos = []): Debt
    {
        return $this->servico()->create(array_merge([
            'kind' => 'financing', 'name' => 'Apartamento', 'amortization' => 'price', 'principal' => '100000.00', 'rate' => '12',
            'rate_basis' => 'annual_nominal', 'term' => 12, 'first_due_date' => '2026-11-05', 'paid_before' => 0,
        ], $this->classificacao('Habitação', 'Prestação da casa'), $campos));
    }

    private function emprestimoComJuros(array $campos = []): Debt
    {
        return $this->servico()->create(array_merge([
            'kind' => 'loan', 'name' => 'Empréstimo caro', 'amortization' => 'sac', 'principal' => '12000.00', 'rate' => '3',
            'rate_basis' => 'monthly', 'term' => 12, 'first_due_date' => '2026-11-10', 'paid_before' => 0,
        ], $this->classificacao(), $campos));
    }

    // ------------------------------------------------------------------ cálculo

    public function test_taxa_implicita_de_parcelas_informadas_volta_a_taxa_do_cronograma(): void
    {
        $linhas = DebtScheduleCalculator::schedule(DebtAmortization::Price, '10000.00', '0.020000000000', 12);

        $taxa = DebtSimulator::impliedMonthlyRate('10000.00', array_column($linhas, 'amount'));

        self::assertEqualsWithDelta(0.02, (float) $taxa, 0.0002);
        self::assertSame('0.00000000', DebtSimulator::impliedMonthlyRate('1000.00', ['500.00', '500.00']), 'sem juros');
        self::assertNull(DebtSimulator::impliedMonthlyRate('0', ['100.00']));
        self::assertNull(DebtSimulator::impliedMonthlyRate('100.00', ['1000.00', '1000.00']), 'taxa fora do razoável: não chuta');
    }

    public function test_antecipar_no_sac_mantendo_a_parcela_encurta_o_prazo(): void
    {
        // 12.000 em 12 parcelas de amortização 1.000, juros de 1% ao mês
        $r = DebtSimulator::prepay(DebtAmortization::Sac, '12000.00', '0.010000000000', '0.00', 12, '1000.00', '1120.00', '6000.00', 'term');

        self::assertFalse($r['paid_off']);
        self::assertSame(6, $r['term']);
        self::assertSame('1060.00', $r['first_installment'], '1.000 de amortização + 1% de 6.000');
        self::assertSame('6000.00', $r['balance_after']);
    }

    public function test_antecipar_mantendo_o_prazo_baixa_a_parcela(): void
    {
        $r = DebtSimulator::prepay(DebtAmortization::Sac, '12000.00', '0.010000000000', '0.00', 12, '1000.00', '1120.00', '6000.00', 'installment');

        self::assertSame(12, $r['term']);
        self::assertSame('560.00', $r['first_installment'], '500 de amortização + 1% de 6.000');
    }

    public function test_antecipar_na_price_mantendo_a_parcela_calcula_o_novo_prazo(): void
    {
        $r = DebtSimulator::prepay(DebtAmortization::Price, '10000.00', '0.020000000000', '0.00', 12, '832.00', '945.60', '5000.00', 'term');

        self::assertSame(6, $r['term']);
        self::assertEqualsWithDelta(892.63, (float) $r['first_installment'], 0.01, 'Price de 5.000 em 6 parcelas a 2%');
    }

    public function test_antecipar_o_saldo_inteiro_quita(): void
    {
        $r = DebtSimulator::prepay(DebtAmortization::Sac, '12000.00', '0.010000000000', '0.00', 12, '1000.00', '1120.00', '12000.00', 'term');

        self::assertTrue($r['paid_off']);
        self::assertSame(0, $r['term']);
    }

    public function test_portabilidade_soma_o_custo_da_operacao_ao_total(): void
    {
        $semCusto = DebtSimulator::port(DebtAmortization::Price, '10000.00', '0.010000000000', 12, '0.00', '0.00');
        $comCusto = DebtSimulator::port(DebtAmortization::Price, '10000.00', '0.010000000000', 12, '0.00', '500.00');

        self::assertSame(12, $semCusto['term']);
        self::assertSame(bcadd($semCusto['total_to_pay'], '500.00', 2), $comCusto['total_to_pay']);
        self::assertSame('500.00', $comCusto['upfront']);
    }

    public function test_simulacao_exige_taxa(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DebtSimulator::port(DebtAmortization::Fixed, '10000.00', '0.01', 12, '0.00', '0.00');
    }

    // ------------------------------------------------------------------ qual quitar primeiro

    public function test_ordena_pela_maior_taxa_e_deixa_sem_taxa_no_fim(): void
    {
        $this->financiamento();                                                   // 1% ao mês
        $this->emprestimoComJuros();                                              // 3% ao mês
        $this->servico()->createOpenDebt(['name' => 'Cartão atrasado', 'current_balance' => '4250.00']);

        $nomes = $this->estrategia()->ranking('rate')->map(fn (array $l) => $l['debt']->name)->all();

        self::assertSame(['Empréstimo caro', 'Apartamento', 'Cartão atrasado'], $nomes);
    }

    public function test_ordena_pelo_menor_saldo(): void
    {
        $this->financiamento();
        $this->emprestimoComJuros();
        $this->servico()->createOpenDebt(['name' => 'Cartão atrasado', 'current_balance' => '4250.00']);

        $nomes = $this->estrategia()->ranking('balance')->map(fn (array $l) => $l['debt']->name)->all();

        self::assertSame(['Cartão atrasado', 'Empréstimo caro', 'Apartamento'], $nomes);
    }

    public function test_taxa_do_contrato_e_a_calculada_pelas_parcelas_quando_nao_ha_taxa(): void
    {
        $this->emprestimoComJuros();
        $this->servico()->create(array_merge([
            'kind' => 'loan', 'name' => 'Empréstimo de parcelas', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'principal' => '3500.00',
            'term' => 6, 'first_due_date' => '2026-11-25', 'paid_before' => 0,
        ], $this->classificacao()));

        $linhas = $this->estrategia()->ranking('rate')->keyBy(fn (array $l) => $l['debt']->name);

        self::assertSame('contrato', $linhas['Empréstimo caro']['rate_source']);
        self::assertSame('parcelas', $linhas['Empréstimo de parcelas']['rate_source']);
        self::assertGreaterThan(0.0, (float) $linhas['Empréstimo de parcelas']['monthly_rate']);
        self::assertGreaterThan(0.0, $linhas['Empréstimo de parcelas']['annual_percent']);
    }

    public function test_consorcio_e_divida_quitada_ficam_fora(): void
    {
        $this->servico()->createConsortium([
            'consortium_type' => 'property', 'credit_amount' => '100000.00', 'installment_amount' => '1000.00', 'term' => 100, 'first_due_date' => '2026-11-10',
        ]);
        $quitada = $this->emprestimoComJuros(['name' => 'Já paga', 'term' => 1, 'paid_before' => 1]);
        $this->financiamento();

        $nomes = $this->estrategia()->ranking()->map(fn (array $l) => $l['debt']->name)->all();

        self::assertSame(['Apartamento'], $nomes);
        self::assertNotNull($quitada);
    }

    // ------------------------------------------------------------------ projeção

    public function test_projecao_soma_parcelas_e_o_saldo_chega_a_zero_no_ultimo_mes(): void
    {
        $this->servico()->create(array_merge([
            'kind' => 'loan', 'name' => 'Empréstimo', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 6, 'first_due_date' => '2026-10-25', 'paid_before' => 0,
        ], $this->classificacao()));

        $p = $this->estrategia()->projection();

        self::assertCount(6, $p['labels']);
        self::assertSame(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03'], $p['months']);
        self::assertSame(650.0, $p['installments'][0]);
        self::assertSame(3250.0, $p['balance'][0], '3.900 - a parcela de outubro');
        self::assertSame(0.0, $p['balance'][5]);
        self::assertSame('3900.00', $p['total_balance']);
    }

    public function test_projecao_poe_o_atrasado_no_mes_atual(): void
    {
        $this->servico()->create(array_merge([
            'kind' => 'loan', 'name' => 'Atrasado', 'amortization' => 'fixed', 'installment_amount' => '100.00', 'term' => 3, 'first_due_date' => '2026-08-10', 'paid_before' => 0,
        ], $this->classificacao()));

        $p = $this->estrategia()->projection();

        self::assertSame('2026-10', $p['months'][0]);
        self::assertSame(300.0, $p['installments'][0], 'ago, set e out juntos no mês atual');
    }

    public function test_projecao_vazia_sem_cronograma_ou_so_com_consorcio_nao_contemplado(): void
    {
        $this->servico()->createOpenDebt(['name' => 'Cartão', 'current_balance' => '1000.00']);
        $this->servico()->createConsortium([
            'consortium_type' => 'property', 'credit_amount' => '100000.00', 'installment_amount' => '1000.00', 'term' => 100, 'first_due_date' => '2026-11-10',
        ]);

        self::assertSame([], $this->estrategia()->projection()['labels']);
    }

    // ------------------------------------------------------------------ telas

    public function test_lista_mostra_qual_quitar_primeiro_e_os_graficos(): void
    {
        $this->financiamento();
        $this->emprestimoComJuros();

        Livewire::test(DebtsIndex::class)
            ->assertSee('Qual quitar primeiro')->assertSee('Como as dívidas caem')->assertSee('Saldo devedor ao fim de cada mês')
            ->assertSeeInOrder(['Empréstimo caro', 'Apartamento'])
            ->set('ordem', 'balance')->assertSee('Pelo menor saldo');
    }

    public function test_lista_com_uma_so_divida_nao_mostra_ranking(): void
    {
        $this->financiamento();

        Livewire::test(DebtsIndex::class)->assertDontSee('Qual quitar primeiro');
    }

    public function test_tela_simula_antecipacao_e_mostra_a_economia(): void
    {
        $divida = $this->financiamento();

        $teste = Livewire::test(DebtShow::class, ['debt' => $divida])
            ->assertSee('Simular antecipação ou portabilidade')
            ->call('openSim')->set('simExtra', '30000')->set('simMode', 'term')->call('simular')->assertHasNoErrors();

        $r = $teste->get('simResult');
        self::assertFalse($r['paid_off']);
        self::assertLessThan(12, $r['term']);
        self::assertTrue(bccomp($r['saving'], '0', 2) > 0, 'antecipar economiza juros');
        $teste->assertSee('Você economiza cerca de');
    }

    public function test_tela_simula_portabilidade_para_taxa_menor_e_maior(): void
    {
        $divida = $this->financiamento();

        $barata = Livewire::test(DebtShow::class, ['debt' => $divida])->call('openSim')->set('simTab', 'port')
            ->set('simRate', '6')->set('simRateBasis', 'annual_nominal')->set('simTerm', '12')->set('simFees', '0')->set('simCost', '200')->call('simular')->assertHasNoErrors();
        self::assertTrue(bccomp($barata->get('simResult')['saving'], '0', 2) > 0);
        $barata->assertSee('A portabilidade economiza');

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openSim')->set('simTab', 'port')
            ->set('simRate', '30')->set('simRateBasis', 'annual_nominal')->set('simTerm', '12')->call('simular')->assertSee('Não compensa');
    }

    public function test_simulador_valida_e_so_existe_para_contrato_com_taxa(): void
    {
        $divida = $this->financiamento();

        Livewire::test(DebtShow::class, ['debt' => $divida])->call('openSim')->set('simTab', 'port')->set('simRate', '')->call('simular')->assertHasErrors(['simRate']);

        $fixo = $this->servico()->create(array_merge([
            'kind' => 'loan', 'name' => 'Sem taxa', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 6, 'first_due_date' => '2026-11-25', 'paid_before' => 0,
        ], $this->classificacao()));

        Livewire::test(DebtShow::class, ['debt' => $fixo])->assertDontSee('Simular antecipação ou portabilidade');

        Livewire::test(DebtShow::class, ['debt' => $fixo])->call('openSim')->assertStatus(404);
    }

    public function test_taxa_de_base_mensal_equivale_a_nominal_dividida_por_12(): void
    {
        self::assertSame(
            DebtScheduleCalculator::monthlyRate('12', DebtRateBasis::AnnualNominal),
            DebtScheduleCalculator::monthlyRate('1', DebtRateBasis::Monthly),
        );
    }
}
