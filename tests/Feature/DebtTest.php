<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\DebtAmortization;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtRateBasis;
use App\Enums\DebtStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\Debts\DebtShow;
use App\Livewire\Debts\DebtsIndex;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\DebtInstallmentDueSoon;
use App\Services\DashboardService;
use App\Services\DebtReminderService;
use App\Services\DebtService;
use App\Support\Debts\DebtClassification;
use App\Support\Debts\DebtScheduleCalculator;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Módulo Dívidas (financiamento e empréstimo): cálculo do cronograma, parcela ligada à despesa, classificação, privacidade,
 * resumo do painel e aviso de vencimento.
 */
class DebtTest extends TestCase
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

    /** @return array{category_id: string, subcategory_id: string} */
    private function classificacao(string $categoria = 'Habitação', string $sub = 'Prestação da casa'): array
    {
        $cat = ExpenseCategory::available()->where('name', $categoria)->firstOrFail();

        return ['category_id' => $cat->id, 'subcategory_id' => $cat->subcategories()->where('name', $sub)->firstOrFail()->id];
    }

    private function financiamento(array $campos = []): Debt
    {
        return $this->servico()->create(array_merge([
            'kind' => 'financing', 'name' => 'Apartamento', 'creditor' => 'Caixa', 'amortization' => 'sac', 'principal' => '120000.00',
            'rate' => '12', 'rate_basis' => 'annual_nominal', 'term' => 12, 'first_due_date' => '2026-11-05', 'monthly_fees' => '50.00',
            'paid_before' => 0, 'member_id' => $this->membro->id,
        ], $this->classificacao(), $campos));
    }

    private function emprestimo(array $campos = []): Debt
    {
        return $this->servico()->create(array_merge([
            'kind' => 'loan', 'name' => 'Empréstimo pessoal', 'amortization' => 'fixed', 'installment_amount' => '650.00', 'term' => 6,
            'first_due_date' => '2026-10-25', 'paid_before' => 0,
        ], $this->classificacao('Financeiros', 'Empréstimo'), $campos));
    }

    // ------------------------------------------------------------------ cálculo do cronograma

    public function test_price_tem_parcela_fixa_e_as_amortizacoes_fecham_no_valor_financiado(): void
    {
        $linhas = DebtScheduleCalculator::schedule(DebtAmortization::Price, '100000.00', DebtScheduleCalculator::monthlyRate('1', DebtRateBasis::Monthly), 12);

        self::assertSame('8884.88', $linhas[0]['amount']);
        self::assertSame('8884.88', $linhas[10]['amount']);
        self::assertSame('100000.00', array_reduce($linhas, fn ($c, $l) => bcadd($c, $l['principal'], 2), '0'));
        self::assertSame('1000.00', $linhas[0]['interest']);
    }

    public function test_sac_tem_amortizacao_constante_e_parcela_que_cai(): void
    {
        $linhas = DebtScheduleCalculator::schedule(DebtAmortization::Sac, '12000.00', DebtScheduleCalculator::monthlyRate('1', DebtRateBasis::Monthly), 12);

        self::assertSame('1120.00', $linhas[0]['amount']);
        self::assertSame('1000.00', $linhas[0]['principal']);
        self::assertSame('1010.00', $linhas[11]['amount']);
        self::assertSame('12000.00', array_reduce($linhas, fn ($c, $l) => bcadd($c, $l['principal'], 2), '0'));
    }

    public function test_taxa_zero_divide_igual_e_a_ultima_parcela_absorve_a_sobra(): void
    {
        $linhas = DebtScheduleCalculator::schedule(DebtAmortization::Price, '1000.00', '0.000000000000', 3);

        self::assertSame(['333.33', '333.33', '333.34'], array_column($linhas, 'amount'));
    }

    public function test_bases_da_taxa(): void
    {
        self::assertSame('0.010000000000', DebtScheduleCalculator::monthlyRate('12', DebtRateBasis::AnnualNominal));
        self::assertSame('0.010000000000', DebtScheduleCalculator::monthlyRate('1', DebtRateBasis::Monthly));
        self::assertEqualsWithDelta(0.01, (float) DebtScheduleCalculator::monthlyRate('12.682503', DebtRateBasis::AnnualEffective), 0.0000001);
    }

    public function test_taxas_fixas_entram_em_cada_parcela(): void
    {
        $linhas = DebtScheduleCalculator::schedule(DebtAmortization::Sac, '1200.00', '0.000000000000', 2, '50.00');

        self::assertSame(['650.00', '650.00'], array_column($linhas, 'amount'));
        self::assertSame('50.00', $linhas[0]['fees']);
    }

    public function test_cronograma_recusa_dados_impossiveis(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DebtScheduleCalculator::schedule(DebtAmortization::Fixed, '1000.00', '0', 3);
    }

    // ------------------------------------------------------------------ criar o contrato

    public function test_cria_financiamento_com_cronograma_e_parcelas_ja_pagas(): void
    {
        $divida = $this->financiamento(['paid_before' => 3, 'first_due_date' => '2026-01-31']);

        $parcelas = $divida->installments;
        self::assertCount(12, $parcelas);
        self::assertSame(3, $parcelas->where('status', DebtInstallmentStatus::Paid)->count());
        self::assertNull($parcelas[0]->expense_id, 'as pagas antes do Cerne não têm despesa');
        // O dia 31 volta ao fim do mês seguinte e depois ao dia 31 (sem derivar para o dia 28).
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $parcelas->take(4)->map(fn ($p) => $p->due_date->toDateString())->all());
        self::assertSame(DebtStatus::Active, $divida->status);
        self::assertSame($this->membro->id, $parcelas[0]->member_id);
    }

    public function test_emprestimo_com_parcelas_informadas_e_total_a_pagar(): void
    {
        $divida = $this->emprestimo(['paid_before' => 2]);

        self::assertSame('2600.00', $divida->balance(), '4 parcelas de 650 ainda por pagar');
        self::assertSame('Total a pagar', $divida->balanceLabel());
        self::assertSame(2, $divida->paidCount());
    }

    public function test_saldo_devedor_do_sac_e_a_soma_das_amortizacoes_em_aberto(): void
    {
        $divida = $this->financiamento(['paid_before' => 2]);

        self::assertSame('100000.00', $divida->balance(), '120 mil, menos 2 amortizações de 10 mil');
        self::assertSame('Saldo devedor', $divida->balanceLabel());
    }

    public function test_recusa_contrato_incompleto_ou_sem_categoria(): void
    {
        foreach ([
            ['term' => 0], ['term' => 500], ['paid_before' => 99], ['category_id' => null], ['principal' => null],
            ['rate' => null], ['kind' => 'consortium'],
        ] as $campos) {
            try {
                $this->financiamento($campos);
                self::fail('deveria recusar '.json_encode($campos));
            } catch (InvalidArgumentException) {
            }
        }

        try {
            $this->emprestimo(['installment_amount' => null]);
            self::fail('sem valor da parcela');
        } catch (InvalidArgumentException) {
        }

        self::assertSame(0, Debt::query()->count(), 'nada ficou gravado');
    }

    public function test_subcategoria_de_outra_categoria_e_recusada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->financiamento(['subcategory_id' => $this->classificacao('Transporte', 'Prestação Carro')['subcategory_id']]);
    }

    public function test_sugestao_de_classificacao_por_tipo_e_bem(): void
    {
        $casa = DebtClassification::suggest(DebtKind::Financing, DebtClassification::PROPERTY);
        $carro = DebtClassification::suggest(DebtKind::Financing, DebtClassification::VEHICLE);
        $emprestimo = DebtClassification::suggest(DebtKind::Loan);

        self::assertSame($this->classificacao()['subcategory_id'], $casa['subcategory_id']);
        self::assertSame($this->classificacao('Transporte', 'Prestação Carro')['subcategory_id'], $carro['subcategory_id']);
        self::assertSame($this->classificacao('Financeiros', 'Empréstimo')['subcategory_id'], $emprestimo['subcategory_id']);
        self::assertSame(['category_id' => null, 'subcategory_id' => null], DebtClassification::suggest(DebtKind::Financing, DebtClassification::OTHER));
    }

    // ------------------------------------------------------------------ pagar e ligar à despesa

    public function test_pagar_cria_a_despesa_na_categoria_do_contrato_e_debita_a_conta(): void
    {
        $conta = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'current_balance' => '5000.00']);
        $divida = $this->financiamento(['bank_account_id' => $conta->id]);
        $parcela = $divida->installments[0];

        $this->servico()->pay($parcela, CarbonImmutable::parse('2026-11-05'));

        $despesa = ExpenseRecord::query()->sole();
        self::assertSame((string) $parcela->amount, $despesa->amount);
        self::assertSame($divida->category_id, $despesa->category_id);
        self::assertSame($divida->subcategory_id, $despesa->subcategory_id);
        self::assertSame(Necessity::Essential, $despesa->necessity);
        self::assertSame('Parcela 1/12 · Apartamento', $despesa->description);
        self::assertSame('2026-11-05', $despesa->expense_date->toDateString());
        self::assertSame(bcsub('5000.00', (string) $parcela->amount, 2), (string) $conta->fresh()->current_balance);

        $parcela->refresh();
        self::assertTrue($parcela->isPaid());
        self::assertSame($despesa->id, $parcela->expense_id);
        self::assertSame($parcela->id, $despesa->debtInstallment->id);
    }

    public function test_valor_pago_pode_diferir_do_previsto_e_parcela_paga_nao_paga_de_novo(): void
    {
        $divida = $this->emprestimo();
        $parcela = $divida->installments[0];

        $this->servico()->pay($parcela, CarbonImmutable::parse('2026-10-26'), '655,40');

        self::assertSame('655.40', ExpenseRecord::query()->sole()->amount);
        self::assertSame('655.40', (string) $parcela->fresh()->paid_amount);
        self::assertSame('650.00', (string) $parcela->fresh()->amount, 'o previsto não muda');

        $this->expectException(InvalidArgumentException::class);
        $this->servico()->pay($parcela->fresh(), CarbonImmutable::parse('2026-10-26'));
    }

    public function test_pagar_a_ultima_quita_e_desfazer_reabre(): void
    {
        $divida = $this->emprestimo(['term' => 2, 'paid_before' => 1]);
        $ultima = $divida->installments[1];

        $this->servico()->pay($ultima, CarbonImmutable::parse('2026-11-25'));
        self::assertSame(DebtStatus::PaidOff, $divida->fresh()->status);

        $this->servico()->unlink($ultima->fresh());
        self::assertSame(DebtStatus::Active, $divida->fresh()->status);
        self::assertSame(1, ExpenseRecord::query()->count(), 'a despesa não é apagada');
        self::assertNull($ultima->fresh()->expense_id);
        self::assertSame(DebtInstallmentStatus::Open, $ultima->fresh()->status);
    }

    public function test_vincular_despesa_existente_marca_a_parcela_e_reclassifica_se_pedido(): void
    {
        $divida = $this->financiamento();
        $outra = $this->classificacao('Lazer', 'Bar');
        $despesa = ExpenseRecord::create([
            'member_id' => $this->membro->id, 'description' => 'Boleto', 'necessity' => Necessity::Discretionary, 'category_id' => $outra['category_id'],
            'subcategory_id' => $outra['subcategory_id'], 'amount' => '2200.00', 'expense_date' => '2026-11-06',
        ]);

        $this->servico()->linkExpense($divida->installments[0], $despesa, true);

        $despesa->refresh();
        self::assertSame($divida->category_id, $despesa->category_id, 'passou para a categoria do contrato');
        self::assertSame(Necessity::Essential, $despesa->necessity);
        $parcela = $divida->installments()->first();
        self::assertTrue($parcela->isPaid());
        self::assertSame('2026-11-06', $parcela->paid_on->toDateString());
        self::assertSame('2200.00', (string) $parcela->paid_amount);
        self::assertSame(1, ExpenseRecord::query()->count(), 'não cria despesa nova');
    }

    public function test_uma_despesa_nao_paga_duas_parcelas(): void
    {
        $divida = $this->financiamento();
        $despesa = ExpenseRecord::create([
            'member_id' => $this->membro->id, 'description' => 'Boleto', 'necessity' => Necessity::Essential, 'category_id' => $divida->category_id,
            'amount' => '2200.00', 'expense_date' => '2026-11-06',
        ]);

        $this->servico()->linkExpense($divida->installments[0], $despesa);

        $this->expectException(InvalidArgumentException::class);
        $this->servico()->linkExpense($divida->installments[1], $despesa);
    }

    public function test_reclassificar_o_contrato_so_vale_para_as_proximas_a_menos_que_se_peca(): void
    {
        $divida = $this->financiamento();
        $this->servico()->pay($divida->installments[0], CarbonImmutable::parse('2026-11-05'));
        $novaCategoria = $this->classificacao('Transporte', 'Prestação Carro');

        $this->servico()->update($divida, $novaCategoria);
        self::assertSame($this->classificacao()['category_id'], ExpenseRecord::query()->sole()->category_id, 'a despesa já paga não mudou');

        $this->servico()->pay($divida->fresh()->installments[1], CarbonImmutable::parse('2026-12-05'));
        self::assertSame($novaCategoria['category_id'], ExpenseRecord::query()->orderByDesc('expense_date')->first()->category_id, 'a próxima já vai na nova categoria');

        $this->servico()->update($divida->fresh(), $novaCategoria, true);
        self::assertSame(2, ExpenseRecord::query()->where('category_id', $novaCategoria['category_id'])->count());
    }

    public function test_ajustar_parcela_move_a_diferenca_para_os_juros(): void
    {
        $divida = $this->financiamento();
        $parcela = $divida->installments[0];
        $juros = (string) $parcela->interest_amount;
        $amortizacao = (string) $parcela->principal_amount;

        $this->servico()->updateInstallment($parcela, bcadd((string) $parcela->amount, '30.00', 2), CarbonImmutable::parse('2026-11-10'));

        $parcela->refresh();
        self::assertSame(bcadd($juros, '30.00', 2), (string) $parcela->interest_amount);
        self::assertSame($amortizacao, (string) $parcela->principal_amount, 'o saldo devedor segue fechando com o contrato');
        self::assertSame('2026-11-10', $parcela->due_date->toDateString());

        $this->servico()->pay($parcela, CarbonImmutable::parse('2026-11-10'));
        $this->expectException(InvalidArgumentException::class);
        $this->servico()->updateInstallment($parcela->fresh(), '10.00', null);
    }

    public function test_apagar_o_contrato_mantem_as_despesas_ja_lancadas(): void
    {
        $divida = $this->emprestimo();
        $this->servico()->pay($divida->installments[0], CarbonImmutable::parse('2026-10-25'));

        $this->servico()->delete($divida);

        self::assertSame(0, Debt::query()->count());
        self::assertSame(0, DebtInstallment::withoutGlobalScopes()->count());
        self::assertSame(1, ExpenseRecord::query()->count());
    }

    // ------------------------------------------------------------------ resumo e painel

    public function test_resumo_soma_o_devedor_e_as_parcelas_do_mes(): void
    {
        $this->emprestimo(['first_due_date' => '2026-10-25']);                       // 6 x 650, a 1ª em outubro
        $financiamento = $this->financiamento(['first_due_date' => '2026-10-05']);    // SAC 12, a 1ª em outubro
        $this->servico()->pay($financiamento->installments[0], CarbonImmutable::parse('2026-10-05'));

        $resumo = $this->servico()->summary(2026, 10);

        $parcelaFin = (string) $financiamento->installments[0]->amount;
        self::assertSame(bcadd($parcelaFin, '650.00', 2), $resumo['month_total']);
        self::assertSame($parcelaFin, $resumo['month_paid']);
        self::assertSame('650.00', $resumo['month_open']);
        // 6 x 650 = 3900 de total a pagar + (120 mil - 10 mil da amortização já paga) = 110 mil de saldo devedor
        self::assertSame('113900.00', $resumo['total']);
        self::assertCount(5, $resumo['next']);
        self::assertSame('2026-10-25', $resumo['next'][0]['due_date']);
    }

    public function test_painel_traz_as_dividas_em_texto_para_o_cache(): void
    {
        $this->emprestimo();

        $dados = app(DashboardService::class)->overview(2026, 10);

        self::assertSame('3900.00', $dados['dividas']['total']);
        self::assertIsString($dados['dividas']['next'][0]['amount']);
    }

    // ------------------------------------------------------------------ privacidade

    public function test_divida_privada_do_conjuge_nao_aparece_para_o_outro_mas_o_consultor_ve(): void
    {
        $conjuge = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id, 'role' => MemberRole::Secondary, 'name' => 'Helen']);
        $privada = $this->emprestimo(['name' => 'Privada', 'member_id' => $this->membro->id, 'is_private' => true]);
        $this->emprestimo(['name' => 'Da familia', 'member_id' => null]);

        app(ProfileContext::class)->set($this->perfil, $conjuge);
        self::assertSame(['Da familia'], Debt::query()->pluck('name')->all());
        self::assertSame(6, DebtInstallment::query()->count(), 'só as parcelas da dívida da família');

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $this->usuario->id, 'status' => ConsultantClientStatus::Active]);
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
        self::assertSame(2, Debt::query()->count());
        self::assertSame($privada->id, Debt::query()->where('name', 'Privada')->sole()->id);
    }

    public function test_mudar_dono_ou_privacidade_do_contrato_vale_nas_parcelas(): void
    {
        $divida = $this->emprestimo(['member_id' => $this->membro->id, 'is_private' => false]);

        $this->servico()->update($divida, ['is_private' => true]);

        self::assertSame(6, DebtInstallment::query()->where('is_private', true)->count());
    }

    // ------------------------------------------------------------------ telas

    public function test_formulario_cadastra_financiamento_e_leva_ao_cronograma(): void
    {
        $teste = Livewire::test(DebtsIndex::class)
            ->call('openForm')
            ->assertSet('amortization', 'sac')
            ->assertSet('categoryId', $this->classificacao()['category_id'])
            ->set('name', 'Apartamento')->set('principal', '360000,00')->set('rate', '10,5')->set('term', '360')->set('firstDueDate', '2026-12-05')
            ->call('save')->assertHasNoErrors();

        $divida = Debt::query()->sole();
        self::assertSame(360, $divida->installments()->count());
        self::assertSame('10.500000', (string) $divida->rate);
        $teste->assertRedirect(route('debts.show', $divida));
    }

    public function test_trocar_o_tipo_ajusta_o_calculo_e_a_categoria_sugerida(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'loan')->assertSet('amortization', 'fixed')->assertSet('subcategoryId', $this->classificacao('Financeiros', 'Empréstimo')['subcategory_id'])
            ->set('kind', 'financing')->set('asset', 'vehicle')->assertSet('subcategoryId', $this->classificacao('Transporte', 'Prestação Carro')['subcategory_id']);
    }

    public function test_formulario_valida_campos_obrigatorios_e_parcelas_pagas(): void
    {
        Livewire::test(DebtsIndex::class)->call('openForm')->call('save')->assertHasErrors(['name', 'principal', 'rate', 'term', 'firstDueDate']);

        Livewire::test(DebtsIndex::class)->call('openForm')
            ->set('kind', 'loan')->set('name', 'X')->set('installmentAmount', '100')->set('term', '3')->set('firstDueDate', '2026-11-01')->set('paidBefore', '5')
            ->call('save')->assertHasErrors(['paidBefore']);

        self::assertSame(0, Debt::query()->count());
    }

    public function test_lista_mostra_os_totais_e_os_contratos(): void
    {
        $this->emprestimo(['name' => 'Empréstimo do carro']);
        $this->financiamento(['name' => 'Apartamento 2']);

        Livewire::test(DebtsIndex::class)->assertSee('Empréstimo do carro')->assertSee('Apartamento 2')->assertSee('Total devedor')->assertSee('Parcelas deste mês');
    }

    public function test_tela_do_contrato_paga_e_desfaz_pelo_componente(): void
    {
        $divida = $this->emprestimo();
        $parcela = $divida->installments[0];

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->assertSee('Parcelas')->assertSee('Pagar')
            ->call('openPay', $parcela->id)->set('payDate', '2026-10-25')->call('pay')->assertHasNoErrors();

        self::assertTrue($parcela->fresh()->isPaid());

        Livewire::test(DebtShow::class, ['debt' => $divida->fresh()])->call('unlink', $parcela->id);
        self::assertFalse($parcela->fresh()->isPaid());
    }

    public function test_tela_vincula_despesa_proxima_ao_vencimento(): void
    {
        $divida = $this->emprestimo();
        $parcela = $divida->installments[0];
        $perto = ExpenseRecord::create(['member_id' => $this->membro->id, 'description' => 'Débito do banco', 'necessity' => Necessity::Essential, 'category_id' => $divida->category_id, 'amount' => '650.00', 'expense_date' => '2026-10-26']);
        ExpenseRecord::create(['member_id' => $this->membro->id, 'description' => 'Compra distante', 'necessity' => Necessity::Essential, 'category_id' => $divida->category_id, 'amount' => '650.00', 'expense_date' => '2025-01-10']);

        Livewire::test(DebtShow::class, ['debt' => $divida])
            ->call('openLink', $parcela->id)->assertSee('Débito do banco')->assertDontSee('Compra distante')
            ->set('linkExpenseId', $perto->id)->call('linkExpense')->assertHasNoErrors();

        self::assertSame($perto->id, $parcela->fresh()->expense_id);
    }

    public function test_corretor_nao_entra_e_contrato_de_outro_perfil_da_404(): void
    {
        $outroUsuario = User::factory()->create();
        $outroPerfil = FinancialProfile::factory()->create(['owner_user_id' => $outroUsuario->id]);
        $divida = $this->emprestimo();

        $this->actingAs($outroUsuario);
        app(ProfileContext::class)->set($outroPerfil, ProfileMember::factory()->create(['profile_id' => $outroPerfil->id, 'user_id' => $outroUsuario->id, 'role' => MemberRole::Primary]));
        self::assertSame(0, Debt::query()->count());
        $this->get(route('debts.show', $divida->id))->assertNotFound();

        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);
        Livewire::test(DebtsIndex::class)->assertForbidden();
    }

    // ------------------------------------------------------------------ aviso de vencimento

    public function test_avisa_a_parcela_que_vence_em_breve_uma_unica_vez(): void
    {
        Notification::fake();
        $divida = $this->emprestimo(['first_due_date' => '2026-10-23']);   // 1ª parcela: 23/10

        $servico = app(DebtReminderService::class);
        $hoje = CarbonImmutable::parse('2026-10-20');

        self::assertSame(1, $servico->notifyUpcoming($hoje, 3));
        self::assertSame(0, $servico->notifyUpcoming($hoje, 3), 'idempotente');
        self::assertSame(0, $servico->notifyUpcoming($hoje, 2), 'outra data, nada vence');

        Notification::assertSentTo($this->usuario, DebtInstallmentDueSoon::class, fn (DebtInstallmentDueSoon $n) => $n->debtId === $divida->id && $n->dueDate === '2026-10-23' && $n->amount === '650.00');
    }

    public function test_nao_avisa_parcela_paga_nem_de_divida_quitada_e_privada_so_avisa_o_dono(): void
    {
        Notification::fake();
        $conjuge = User::factory()->create();
        ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $conjuge->id, 'role' => MemberRole::Secondary, 'name' => 'Helen']);

        $paga = $this->emprestimo(['name' => 'Paga', 'first_due_date' => '2026-10-23']);
        $this->servico()->pay($paga->installments[0], CarbonImmutable::parse('2026-10-20'));
        $this->emprestimo(['name' => 'Privada', 'first_due_date' => '2026-10-23', 'member_id' => $this->membro->id, 'is_private' => true]);

        $avisos = app(DebtReminderService::class)->notifyUpcoming(CarbonImmutable::parse('2026-10-20'), 3);

        self::assertSame(1, $avisos);
        Notification::assertSentTo($this->usuario, DebtInstallmentDueSoon::class, fn ($n) => str_contains($n->title, 'Privada'));
        Notification::assertNotSentTo($conjuge, DebtInstallmentDueSoon::class);
    }

    public function test_clique_no_aviso_abre_a_divida_no_perfil_certo(): void
    {
        $divida = $this->emprestimo(['first_due_date' => '2026-10-23']);
        $this->usuario->notify(DebtInstallmentDueSoon::forInstallment($divida->installments[0]));

        $notificacao = $this->usuario->notifications()->sole();

        self::assertSame($this->perfil->id, \App\Support\NotificationPresenter::profileId($notificacao));
        self::assertSame(route('debts.show', $divida->id), \App\Support\NotificationPresenter::present($notificacao)['url']);
    }
}
