<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\IncomeCategory;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Ao salvar" nas Contas fixas: o que o cadastro avisa (vencimentos do mês que nascem na hora, o que "Pagar" e "Receber" fazem). O aviso usa a mesma
 * regra de recorrência que grava, e os testes conferem o texto e o que de fato foi gravado.
 */
class FixedBillsConsequenceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        // Hoje é 10/12/2026: o cadastro cria os vencimentos de dezembro de 2026.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-10 09:00:00'));

        $usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $usuario->id, 'name' => 'Ana']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function formularioDeConta(string $recorrencia = 'monthly')
    {
        return Livewire::test(FixedBillsIndex::class)->call('toggleBillForm')->set('billRecurrence', $recorrencia);
    }

    private function formularioDeReceita(string $recorrencia = 'monthly')
    {
        return Livewire::test(FixedBillsIndex::class)->call('setTab', 'receitas')->call('toggleIncomeForm')->set('incomeRecurrence', $recorrencia);
    }

    public function test_ao_abrir_o_cadastro_a_conta_fixa_ja_diz_que_sem_conta_pagar_nao_cria_despesa(): void
    {
        $this->formularioDeConta()
            ->assertSee('Ao salvar')
            ->assertSee('Sem conta, “Pagar” só marca como paga: nenhuma despesa é criada.')
            ->assertSee('Nenhuma (só lembrete)')
            ->assertDontSee('Nenhuma — só lembrete');
    }

    public function test_conta_mensal_diz_o_vencimento_do_mes_e_ele_e_criado_de_verdade(): void
    {
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        $this->formularioDeConta()
            ->set('billDueDay', '5')
            ->assertSee('O vencimento deste mês (05/12) será criado agora, mesmo que a data já tenha passado.')
            ->set('billName', 'Internet')->set('billAmount', '120.00')->set('billNecessity', 'essential')
            ->set('billCategoryId', $categoria->id)->set('billSubcategoryId', $sub->id)
            ->call('saveBill')->assertHasNoErrors();

        $conta = FixedBill::withoutProfileScope()->where('name', 'Internet')->sole();
        $this->assertSame(['2026-12-05'], FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $conta->id)->get()->map(fn ($p) => $p->due_date->toDateString())->all());
    }

    public function test_conta_semanal_diz_quantos_vencimentos_nascem_e_sao_os_mesmos_gravados(): void
    {
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $sub = ExpenseSubcategory::factory()->create(['category_id' => $categoria->id]);

        // Sextas de dezembro de 2026: 4, 11, 18 e 25.
        $this->formularioDeConta('weekly')
            ->set('billDueWeekday', '5')
            ->assertSee('Serão criados agora os 4 vencimentos deste mês (de 04/12 a 25/12), mesmo os que já passaram.')
            ->set('billName', 'Feira')->set('billAmount', '80.00')->set('billNecessity', 'essential')
            ->set('billCategoryId', $categoria->id)->set('billSubcategoryId', $sub->id)
            ->call('saveBill')->assertHasNoErrors();

        $conta = FixedBill::withoutProfileScope()->where('name', 'Feira')->sole();
        $this->assertSame(4, FixedBillPayment::withoutProfileScope()->where('fixed_bill_id', $conta->id)->count());
    }

    public function test_conta_anual_de_outro_mes_nao_cria_vencimento_agora(): void
    {
        $this->formularioDeConta('annual')
            ->set('billDueMonth', '3')->set('billDueDay', '15')
            ->assertDontSee('será criado agora')
            ->assertDontSee('Serão criados agora');
    }

    public function test_conta_anual_do_mes_corrente_diz_o_vencimento(): void
    {
        $this->formularioDeConta('annual')
            ->set('billDueMonth', '12')->set('billDueDay', '15')
            ->assertSee('O vencimento deste mês (15/12) será criado agora');
    }

    public function test_com_conta_o_aviso_diz_o_que_pagar_cria(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create();
        $categoria = ExpenseCategory::factory()->create(['necessity' => null, 'name' => 'Moradia']);

        $this->formularioDeConta()
            ->set('billAmount', '300.00')->set('billNecessity', 'essential')->set('billCategoryId', $categoria->id)
            ->set('billBankAccountId', $conta->id)
            ->assertSee('Ao pagar, será criada uma despesa de R$ 300,00 em Moradia, com a data do pagamento, e '.$conta->displayName().' será debitada.');
    }

    public function test_conta_de_valor_variavel_avisa_que_o_valor_vem_na_hora_de_pagar(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create();

        $this->formularioDeConta()
            ->set('billBankAccountId', $conta->id)->set('billIsVariable', true)
            ->assertSee('do valor que você informar ao pagar')
            ->assertSee('Ao pagar, você informa o valor real. Este é só uma estimativa.');
    }

    public function test_ao_editar_o_aviso_diz_a_partir_de_quando_a_mudanca_vale(): void
    {
        $categoria = ExpenseCategory::factory()->create(['necessity' => null]);
        $conta = FixedBill::factory()->for($this->perfil, 'profile')->create(['category_id' => $categoria->id, 'due_day' => 5]);

        Livewire::test(FixedBillsIndex::class)->call('editBill', $conta->id)
            ->assertSee('valem a partir do próximo pagamento')
            ->assertDontSee('será criado agora');
    }

    public function test_dia_do_vencimento_em_branco_mostra_mensagem_em_portugues(): void
    {
        $this->formularioDeConta()
            ->set('billName', 'Internet')->set('billAmount', '10.00')
            ->call('saveBill')
            ->assertSee('Informe o dia do vencimento.')
            ->assertDontSee('quando recorrência for');
    }

    public function test_receita_recorrente_diz_o_recebimento_do_mes_e_o_que_receber_cria(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create();
        $categoria = IncomeCategory::factory()->create(['name' => 'Salário']);

        $tela = $this->formularioDeReceita()
            ->set('incomeDueDay', '5')->set('incomeAmount', '5000.00')->set('incomeCategoryId', $categoria->id)
            ->assertSee('O recebimento deste mês (05/12) será criado agora, mesmo que a data já tenha passado.')
            ->assertSee('Sem conta, “Receber” só marca como recebida: nenhuma receita é criada.')
            ->set('incomeBankAccountId', $conta->id)
            ->assertSee('Ao receber, será criada uma receita de R$ 5.000,00 em Salário, com a data do recebimento, e '.$conta->displayName().' será creditada.');

        $tela->set('incomeName', 'Salário')->call('saveIncome')->assertHasNoErrors();

        $receita = RecurringIncome::withoutProfileScope()->where('name', 'Salário')->sole();
        $this->assertSame(['2026-12-05'], RecurringIncomeOccurrence::withoutProfileScope()->where('recurring_income_id', $receita->id)->get()->map(fn ($o) => $o->due_date->toDateString())->all());
    }

    public function test_receita_recorrente_em_branco_mostra_mensagem_em_portugues(): void
    {
        $this->formularioDeReceita()
            ->set('incomeName', 'Salário')->set('incomeAmount', '10.00')
            ->call('saveIncome')
            ->assertSee('Informe o dia do recebimento.')
            ->assertDontSee('quando recorrência for');
    }

    public function test_dica_de_privacidade_diz_quem_vai_ver_a_conta_e_a_despesa_gerada(): void
    {
        $bruno = User::factory()->create();
        ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $bruno->id, 'name' => 'Bruno', 'role' => MemberRole::Secondary]);

        $this->formularioDeConta()
            ->set('billMemberId', $this->membro->id)
            ->assertSee('Só Ana e o consultor verão esta conta e a despesa criada ao pagar.');
    }
}
