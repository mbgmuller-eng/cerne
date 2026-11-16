<?php

namespace Tests\Feature;

use App\Enums\ConsortiumType;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Enums\RecurringIncomeStatus;
use App\Models\BankAccount;
use App\Models\BudgetRule;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Models\User;
use App\Services\BudgetRuleService;
use App\Support\Budget\BudgetStructure;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O motor da regra de orçamento: receita do mês (recebida + recorrente prevista), metas por área e por item, compromissos fixos descontados antes,
 * o realizado por categoria e a privacidade do casal. Hoje é 12/11/2026; o mês de referência é novembro.
 */
class BudgetRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $ana;

    private ProfileMember $bruno;

    private User $usuarioAna;

    private User $usuarioBruno;

    /** @var array<string, ExpenseCategory> */
    private array $cat = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 09:00:00'));

        $this->usuarioAna = User::factory()->create();
        $this->usuarioBruno = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuarioAna->id]);
        $this->ana = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioAna->id, 'role' => MemberRole::Primary, 'name' => 'Ana']);
        $this->bruno = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuarioBruno->id, 'role' => MemberRole::Secondary, 'name' => 'Bruno']);
        $this->como($this->usuarioAna, $this->ana);

        foreach (['Seguro de Vida', 'Reserva de Paz', 'Reserva de Oportunidade', 'Previdência Privada', 'Ações', 'FIIs', 'Consórcio', 'Poupança', 'Fundos', 'Cripto'] as $nome) {
            $this->cat[$nome] = ExpenseCategory::factory()->shared()->create(['name' => $nome, 'necessity' => Necessity::Investment]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function como(User $usuario, ProfileMember $membro, bool $consultor = false): void
    {
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $membro, $consultor);
    }

    private function servico(): BudgetRuleService
    {
        return app(BudgetRuleService::class);
    }

    private function regra(?array $estrutura = null, int $ano = 2026, int $mes = 1): BudgetRule
    {
        return BudgetRule::create(['profile_id' => $this->perfil->id, 'effective_year' => $ano, 'effective_month' => $mes, 'structure' => BudgetStructure::normalize($estrutura ?? BudgetStructure::default())]);
    }

    private function receita(string $valor, string $data = '2026-11-05'): IncomeRecord
    {
        return IncomeRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => null, 'amount' => $valor, 'received_date' => $data]);
    }

    private function gasto(string $necessidade, string $valor, ?string $categoria = null, string $data = '2026-11-05', array $campos = []): ExpenseRecord
    {
        $investimento = $necessidade === 'investment';

        return ExpenseRecord::factory()->create(array_merge([
            'profile_id' => $this->perfil->id, 'member_id' => null, 'necessity' => $necessidade, 'amount' => $valor, 'expense_date' => $data,
            'category_id' => $categoria !== null ? $this->cat[$categoria]->id : ExpenseCategory::factory()->shared()->create()->id,
            'subcategory_id' => $investimento ? null : \Illuminate\Support\Facades\DB::raw('NULL'),
        ], $campos));
    }

    /** O nó (área, grupo ou item) pelo caminho de nomes. */
    private function item(array $relatorio, string $grupo, ?string $item = null): array
    {
        $g = collect($relatorio['areas']['investment']['groups'])->firstWhere('name', $grupo);

        return $item === null ? $g : collect($g['items'])->firstWhere('name', $item);
    }

    // ------------------------------------------------------------------ a estrutura

    public function test_os_modelos_prontos_sao_validos_e_a_normalizacao_e_estavel(): void
    {
        foreach (array_keys(BudgetStructure::presets()) as $chave) {
            $s = BudgetStructure::normalize(BudgetStructure::preset($chave));

            self::assertSame([], BudgetStructure::validate($s), $chave);
            self::assertSame($s, BudgetStructure::normalize($s), 'normalizar de novo não muda nada');
        }

        $cerne = BudgetStructure::preset('cerne')['areas'];
        self::assertSame(['essential' => '50', 'discretionary' => '20', 'investment' => '30'], $cerne);
    }

    public function test_a_validacao_aponta_o_que_esta_errado_em_portugues(): void
    {
        $s = BudgetStructure::normalize(BudgetStructure::default());

        $s['areas']['essential'] = '60.00';
        self::assertStringContainsString('somam 110%', implode(' ', BudgetStructure::validate($s)));

        $s = BudgetStructure::normalize(BudgetStructure::default());
        $s['groups'][0]['percent'] = '10.00';
        self::assertStringContainsString('Os grupos de investimentos somam', implode(' ', BudgetStructure::validate($s)));

        $s = BudgetStructure::normalize(BudgetStructure::default());
        $s['groups'][0]['items'][1]['percent'] = '70.00';
        self::assertStringContainsString('Os itens com percentual do grupo "Reservas" somam 120%', implode(' ', BudgetStructure::validate($s)));

        $s = BudgetStructure::normalize(BudgetStructure::default());
        $s['groups'][1]['items'][0]['categories'][] = 'Reserva de Paz';
        self::assertStringContainsString('"Reserva de Paz" está em mais de um item', implode(' ', BudgetStructure::validate($s)));

        $s = BudgetStructure::normalize(BudgetStructure::default());
        $s['groups'][0]['items'][0]['sources'] = [];
        self::assertStringContainsString('precisa de uma origem do valor', implode(' ', BudgetStructure::validate($s)));

        $s = BudgetStructure::normalize(BudgetStructure::default());
        $s['groups'][0]['items'][1]['kind'] = 'goals';
        self::assertStringContainsString('Só um item pode ser o de objetivos', implode(' ', BudgetStructure::validate($s)));
    }

    // ------------------------------------------------------------------ a receita do mês

    public function test_a_receita_e_o_que_entrou_mais_o_previsto_nas_recorrentes_e_nao_conta_duas_vezes(): void
    {
        $this->receita('8000.00');

        $salario = RecurringIncome::factory()->create(['profile_id' => $this->perfil->id, 'amount' => '2000.00']);
        RecurringIncomeOccurrence::create(['profile_id' => $this->perfil->id, 'recurring_income_id' => $salario->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-20', 'status' => RecurringIncomeStatus::Pending]);

        // Já recebida: virou lançamento (contado em "recebida"), não conta de novo como prevista.
        $bonus = RecurringIncome::factory()->create(['profile_id' => $this->perfil->id, 'amount' => '500.00']);
        RecurringIncomeOccurrence::create(['profile_id' => $this->perfil->id, 'recurring_income_id' => $bonus->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-10', 'status' => RecurringIncomeStatus::Received, 'amount_received' => '500.00']);

        // Pausada não prevê nada; de outro mês também não.
        $pausada = RecurringIncome::factory()->create(['profile_id' => $this->perfil->id, 'amount' => '900.00', 'is_active' => false]);
        RecurringIncomeOccurrence::create(['profile_id' => $this->perfil->id, 'recurring_income_id' => $pausada->id, 'year' => 2026, 'month' => 11, 'due_date' => '2026-11-25', 'status' => RecurringIncomeStatus::Pending]);
        RecurringIncomeOccurrence::create(['profile_id' => $this->perfil->id, 'recurring_income_id' => $salario->id, 'year' => 2026, 'month' => 12, 'due_date' => '2026-12-20', 'status' => RecurringIncomeStatus::Pending]);

        $renda = $this->servico()->income(2026, 11);

        self::assertSame('8000.00', $renda['received']);
        self::assertSame('2000.00', $renda['expected']);
        self::assertSame('10000.00', $renda['total']);
    }

    public function test_receita_recorrente_semanal_prevista_conta_cada_ocorrencia(): void
    {
        $semanal = RecurringIncome::factory()->weekly(5)->create(['profile_id' => $this->perfil->id, 'amount' => '300.00']);

        foreach (['2026-11-06', '2026-11-13', '2026-11-20', '2026-11-27'] as $data) {
            RecurringIncomeOccurrence::create(['profile_id' => $this->perfil->id, 'recurring_income_id' => $semanal->id, 'year' => 2026, 'month' => 11, 'due_date' => $data, 'status' => RecurringIncomeStatus::Pending]);
        }

        self::assertSame('1200.00', $this->servico()->income(2026, 11)['expected']);
    }

    // ------------------------------------------------------------------ as metas

    public function test_sem_regra_nao_ha_relatorio(): void
    {
        self::assertNull($this->servico()->report(2026, 11));
    }

    public function test_a_regra_vale_do_mes_de_inicio_em_diante(): void
    {
        $this->regra(null, 2026, 11);
        $dezembro = $this->regra(['areas' => ['essential' => '40', 'discretionary' => '30', 'investment' => '30']] + BudgetStructure::default(), 2026, 12);

        self::assertNull($this->servico()->ruleFor(2026, 10), 'antes do início não vale');
        self::assertSame('50.00', BudgetStructure::normalize((array) $this->servico()->ruleFor(2026, 11)->structure)['areas']['essential']);
        self::assertSame($dezembro->id, $this->servico()->ruleFor(2026, 12)->id);
        self::assertSame($dezembro->id, $this->servico()->ruleFor(2027, 3)->id, 'vale até outra regra começar');
    }

    public function test_as_metas_por_area_e_por_item_com_o_seguro_e_o_consorcio_descontados_antes(): void
    {
        $this->regra();
        $this->receita('10000.00');

        // O que está CADASTRADO: apólice de vida (150/mês) e parcela de consórcio ainda não contemplado (300 em novembro).
        InsurancePolicy::factory()->life()->create(['profile_id' => $this->perfil->id, 'monthly_premium' => '150.00']);
        InsurancePolicy::factory()->create(['profile_id' => $this->perfil->id, 'monthly_premium' => '999.00']); // de carro: não é seguro de vida
        $this->consorcio('300.00');

        // O que foi LANÇADO.
        $this->gasto('essential', '4800.00');
        $this->gasto('discretionary', '2300.00');
        $this->gasto('investment', '150.00', 'Seguro de Vida');
        $this->gasto('investment', '400.00', 'Reserva de Paz');
        $this->gasto('investment', '400.00', 'Previdência Privada');
        $this->gasto('investment', '300.00', 'Ações');
        $this->gasto('investment', '200.00', 'FIIs');
        $this->gasto('investment', '300.00', 'Consórcio');
        $this->gasto('investment', '100.00', 'Poupança');
        $this->gasto('investment', '50.00', 'Cripto'); // nenhuma folha da regra reivindica

        $r = $this->servico()->report(2026, 11);

        self::assertSame('10000.00', $r['income']['total']);

        $essencial = $r['areas']['essential'];
        self::assertSame('5000.00', $essencial['target']);
        self::assertSame('4800.00', $essencial['realized']);
        self::assertSame('ok', $essencial['status'], 'gastar menos que o limite é ok');

        $superfluo = $r['areas']['discretionary'];
        self::assertSame('2000.00', $superfluo['target']);
        self::assertSame('300.00', $superfluo['over']);
        self::assertSame('over', $superfluo['status'], 'passar do limite do supérfluo');

        $invest = $r['areas']['investment'];
        self::assertSame('3000.00', $invest['target']);
        self::assertSame('1900.00', $invest['realized']);
        self::assertSame('1100.00', $invest['missing']);
        self::assertSame('partial', $invest['status']);

        // Reservas: 1.000, menos o seguro de vida (150 fixo), o resto (850) meio a meio.
        $reservas = $this->item($r, 'Reservas');
        self::assertSame('1000.00', $reservas['target']);
        self::assertSame('150.00', $reservas['fixed_total']);
        self::assertSame('850.00', $reservas['remaining']);
        self::assertNull($reservas['over_commitment']);

        $seguro = $this->item($r, 'Reservas', 'Seguro de vida');
        self::assertSame('150.00', $seguro['committed']);
        self::assertSame('1 apólice de vida', $seguro['committed_detail']);
        self::assertSame('paid', $seguro['validation']);

        $paz = $this->item($r, 'Reservas', 'Reserva de paz');
        self::assertSame('425.00', $paz['target']);
        self::assertSame('400.00', $paz['realized']);
        self::assertSame('25.00', $paz['missing']);
        self::assertSame('partial', $paz['status']);

        $oportunidade = $this->item($r, 'Reservas', 'Reserva de oportunidade');
        self::assertSame('425.00', $oportunidade['target']);
        self::assertSame('none', $oportunidade['status']);

        // Aposentadoria: 1.000, previdência 40% e ações e FIIs 60%.
        self::assertSame('400.00', $this->item($r, 'Aposentadoria', 'Previdência privada')['target']);
        self::assertSame('reached', $this->item($r, 'Aposentadoria', 'Previdência privada')['status']);
        $renda = $this->item($r, 'Aposentadoria', 'Ações e FIIs (renda passiva)');
        self::assertSame('600.00', $renda['target']);
        self::assertSame('500.00', $renda['realized']);

        // Objetivos: 1.000, menos o consórcio (300 fixo): sobram 700 para os objetivos.
        $objetivos = $this->item($r, 'Objetivos');
        self::assertSame('300.00', $objetivos['fixed_total']);
        self::assertSame('700.00', $objetivos['remaining']);
        self::assertSame('paid', $this->item($r, 'Objetivos', 'Consórcio')['validation']);
        $balde = $this->item($r, 'Objetivos', 'Objetivos');
        self::assertSame('700.00', $balde['target']);
        self::assertSame('100.00', $balde['realized']);

        // O lançado numa categoria que nenhum item reivindica aparece, para nada sumir.
        self::assertSame([['name' => 'Cripto', 'amount' => '50.00']], $invest['unmapped']);
    }

    public function test_o_cadastrado_x_lancado_do_compromisso_fixo(): void
    {
        $this->regra();
        $this->receita('10000.00');
        $this->consorcio('300.00');

        $r = $this->servico()->report(2026, 11);
        self::assertSame('pending', $this->item($r, 'Objetivos', 'Consórcio')['validation'], 'cadastrado, nada lançado ainda');
        self::assertSame('unregistered', $this->item($r, 'Reservas', 'Seguro de vida')['validation'], 'nada cadastrado nem lançado');

        $this->gasto('investment', '120.00', 'Consórcio');
        $r = $this->servico()->report(2026, 11);
        self::assertSame('partial', $this->item($r, 'Objetivos', 'Consórcio')['validation']);
    }

    public function test_compromisso_maior_que_o_balde_avisa_e_nao_deixa_o_resto_negativo(): void
    {
        $this->regra();
        $this->receita('10000.00');
        InsurancePolicy::factory()->life()->create(['profile_id' => $this->perfil->id, 'monthly_premium' => '1500.00']);

        $reservas = $this->item($this->servico()->report(2026, 11), 'Reservas');

        self::assertSame('1500.00', $reservas['fixed_total']);
        self::assertSame('500.00', $reservas['over_commitment']);
        self::assertSame('0.00', $reservas['remaining']);
        self::assertSame('0.00', $this->item($this->servico()->report(2026, 11), 'Reservas', 'Reserva de paz')['target']);
    }

    public function test_o_consorcio_contemplado_e_o_de_outro_mes_nao_entram_no_compromisso(): void
    {
        $this->regra();
        $this->receita('10000.00');
        $this->consorcio('300.00', ['contemplated_on' => '2026-10-01']); // contemplado: a parcela virou despesa comum
        $this->consorcio('450.00', [], '2026-12-15');                     // parcela só em dezembro

        self::assertSame('0.00', $this->item($this->servico()->report(2026, 11), 'Objetivos', 'Consórcio')['committed']);
    }

    public function test_a_conta_fixa_de_investimento_entra_como_compromisso_do_item_pelas_categorias(): void
    {
        $estrutura = BudgetStructure::default();
        $estrutura['groups'][1]['items'][0] = ['key' => 'previdencia', 'name' => 'Previdência privada', 'kind' => 'fixed', 'percent' => '0', 'sources' => ['fixed_bills'], 'categories' => ['Previdência Privada']];
        $estrutura['groups'][1]['items'][1]['percent'] = '100';
        $this->regra($estrutura);
        $this->receita('10000.00');

        FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'amount' => '350.00', 'necessity' => Necessity::Investment, 'category_id' => $this->cat['Previdência Privada']->id, 'subcategory_id' => null]);
        FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'amount' => '999.00', 'necessity' => Necessity::Investment, 'category_id' => $this->cat['Poupança']->id, 'subcategory_id' => null]);

        $previdencia = $this->item($this->servico()->report(2026, 11), 'Aposentadoria', 'Previdência privada');

        self::assertSame('350.00', $previdencia['committed']);
        self::assertSame('1 conta fixa', $previdencia['committed_detail']);
        self::assertSame('650.00', $this->item($this->servico()->report(2026, 11), 'Aposentadoria', 'Ações e FIIs (renda passiva)')['target'], 'o restante do balde vai para os itens com percentual');
    }

    public function test_simular_uma_regra_ainda_nao_salva_para_a_previa_do_editor(): void
    {
        $this->receita('10000.00');

        $r = $this->servico()->report(2026, 11, ['areas' => ['essential' => '40', 'discretionary' => '10', 'investment' => '50']] + BudgetStructure::default());

        self::assertSame('5000.00', $r['areas']['investment']['target']);
        self::assertNull($r['effective']);
        self::assertSame(0, BudgetRule::query()->count(), 'a prévia não grava nada');
    }

    // ------------------------------------------------------------------ privacidade do casal

    public function test_o_gasto_privado_do_conjuge_nao_entra_no_realizado_de_quem_nao_o_enxerga(): void
    {
        $this->regra();
        $this->receita('10000.00');
        $this->gasto('investment', '500.00', 'Ações', '2026-11-05', ['member_id' => $this->ana->id, 'is_private' => true]);
        $this->gasto('investment', '200.00', 'Ações');

        $this->como($this->usuarioAna, $this->ana);
        self::assertSame('700.00', $this->servico()->report(2026, 11)['areas']['investment']['realized'], 'a Ana vê os dois');

        $this->como($this->usuarioBruno, $this->bruno);
        self::assertSame('200.00', $this->servico()->report(2026, 11)['areas']['investment']['realized'], 'o Bruno não soma o privado da Ana');

        $this->como($this->usuarioBruno, $this->bruno, true);
        self::assertSame('700.00', $this->servico()->report(2026, 11)['areas']['investment']['realized'], 'o consultor vinculado enxerga tudo');
    }

    // ------------------------------------------------------------------ transferência x aporte

    public function test_o_mesmo_valor_lancado_na_conta_corrente_e_na_de_investimento_perto_em_dias_gera_aviso(): void
    {
        $this->regra();
        $this->receita('10000.00');
        $corrente = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'account_type' => 'checking']);
        $corretora = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'account_type' => 'investment_account']);

        $this->gasto('investment', '1000.00', 'Ações', '2026-11-04', ['bank_account_id' => $corrente->id, 'description' => 'TED para a corretora']);
        $this->gasto('investment', '1000.00', 'Ações', '2026-11-06', ['bank_account_id' => $corretora->id, 'description' => 'Compra de ações']);

        $avisos = $this->servico()->report(2026, 11)['double_count'];

        self::assertCount(1, $avisos);
        self::assertSame('1000.00', $avisos[0]['amount']);
        self::assertSame('TED para a corretora', $avisos[0]['first']);
    }

    public function test_sem_conta_de_investimento_ou_com_datas_distantes_nao_ha_aviso(): void
    {
        $this->regra();
        $this->receita('10000.00');
        $a = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'account_type' => 'checking']);
        $b = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'account_type' => 'savings']);
        $c = BankAccount::factory()->create(['profile_id' => $this->perfil->id, 'account_type' => 'investment_account']);

        $this->gasto('investment', '1000.00', 'Ações', '2026-11-04', ['bank_account_id' => $a->id]);
        $this->gasto('investment', '1000.00', 'Ações', '2026-11-05', ['bank_account_id' => $b->id]); // duas contas, nenhuma de investimento
        $this->gasto('investment', '1000.00', 'FIIs', '2026-11-28', ['bank_account_id' => $c->id]); // de investimento, mas 24 dias depois

        self::assertSame([], $this->servico()->report(2026, 11)['double_count']);
    }

    // ------------------------------------------------------------------ ajudantes

    private function consorcio(string $parcela, array $campos = [], string $vencimento = '2026-11-15'): Debt
    {
        $debt = Debt::withoutGlobalScopes()->create(array_merge([
            'profile_id' => $this->perfil->id, 'kind' => DebtKind::Consortium, 'status' => DebtStatus::Active, 'name' => 'Consórcio imóvel',
            'creditor' => 'Porto Seguro Consórcio', 'consortium_type' => ConsortiumType::Property, 'credit_amount' => '50000.00', 'term' => 60,
            'first_due_date' => $vencimento, 'installment_amount' => $parcela,
        ], $campos));

        DebtInstallment::withoutGlobalScopes()->create([
            'profile_id' => $this->perfil->id, 'debt_id' => $debt->id, 'number' => 1, 'due_date' => $vencimento, 'amount' => $parcela, 'status' => DebtInstallmentStatus::Open,
        ]);

        return $debt;
    }
}
