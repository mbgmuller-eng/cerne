<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Ao salvar": o que o formulário de lançamento avisa antes de gravar. O texto sai da MESMA conta que grava (fatura, parcelas, saldo), e aqui conferimos
 * as duas pontas: o que o aviso diz e o que de fato foi gravado.
 */
class CashFlowConsequenceTest extends TestCase
{
    use RefreshDatabase;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('pt_BR');
        // O mês exibido na tela é o de hoje: dezembro de 2026.
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

    private function cartao(): CreditCard
    {
        return CreditCard::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['closing_day' => 20, 'due_day' => 28]);
    }

    /** @return array{ExpenseCategory, ExpenseSubcategory} */
    private function categoria(): array
    {
        $categoria = ExpenseCategory::factory()->create();

        return [$categoria, ExpenseSubcategory::factory()->create(['category_id' => $categoria->id])];
    }

    private function despesaNoCartao(CreditCard $cartao, string $valor, string $data, int $parcelas = 1)
    {
        return Livewire::test(CashFlowIndex::class)
            ->call('toggleExpenseForm')
            ->set('expensePaymentMethod', 'cartao')
            ->set('expenseCreditCardId', $cartao->id)
            ->set('expenseAmount', $valor)
            ->set('expenseDate', $data)
            ->set('expenseInstallments', $parcelas);
    }

    public function test_compra_no_cartao_diz_a_fatura_o_vencimento_e_que_nenhuma_conta_e_debitada(): void
    {
        $this->despesaNoCartao($this->cartao(), '100.00', '2026-12-10')
            ->assertSee('Ao salvar')
            ->assertSee('Entra na fatura de dezembro de 2026, com vencimento em 28/12/2026. Nenhuma conta é debitada agora.');
    }

    public function test_compra_depois_do_fechamento_cai_na_fatura_seguinte(): void
    {
        $this->despesaNoCartao($this->cartao(), '100.00', '2026-12-25')
            ->assertSee('Entra na fatura de janeiro de 2027, com vencimento em 28/01/2027.');
    }

    public function test_compra_parcelada_diz_as_parcelas_e_o_ajuste_de_centavos(): void
    {
        $this->despesaNoCartao($this->cartao(), '1000.00', '2026-12-10', 3)
            ->assertSee('3 parcelas de R$ 333,33, da fatura de dezembro de 2026 até a de fevereiro de 2027.')
            ->assertSee('A última parcela ajusta os centavos: R$ 333,34.');
    }

    public function test_o_que_o_aviso_diz_e_o_que_e_gravado(): void
    {
        [$categoria, $sub] = $this->categoria();

        $tela = $this->despesaNoCartao($this->cartao(), '1000.00', '2026-12-25', 3)
            ->set('expenseDescription', 'Geladeira')
            ->set('expenseNecessity', 'essential')
            ->set('expenseCategoryId', $categoria->id)
            ->set('expenseSubcategoryId', $sub->id)
            // Depois do fechamento (dia 20): a 1ª parcela cai em janeiro, e a última em março.
            ->assertSee('3 parcelas de R$ 333,33, da fatura de janeiro de 2027 até a de março de 2027.');

        $tela->call('saveExpense')->assertHasNoErrors();

        $parcelas = ExpenseRecord::withoutProfileScope()->where('description', 'like', 'Geladeira%')->orderBy('installment_number')->get();
        $faturas = CreditCardInvoice::withoutProfileScope()->whereIn('id', $parcelas->pluck('credit_card_invoice_id'))->get()->keyBy('id');

        $this->assertSame(['333.33', '333.33', '333.34'], $parcelas->pluck('amount')->all());
        $this->assertSame(['2027-1', '2027-2', '2027-3'], $parcelas->map(fn ($p) => $faturas[$p->credit_card_invoice_id]->year.'-'.$faturas[$p->credit_card_invoice_id]->month)->all());
    }

    public function test_compra_em_fatura_ja_paga_avisa_e_o_valor_pago_nao_muda(): void
    {
        $cartao = $this->cartao();
        [$categoria, $sub] = $this->categoria();
        $fatura = CreditCardInvoice::withoutProfileScope()->create([
            'profile_id' => $this->perfil->id, 'credit_card_id' => $cartao->id, 'year' => 2026, 'month' => 12, 'closing_date' => '2026-12-20', 'due_date' => '2026-12-28',
            'total_amount' => '200.00', 'status' => InvoiceStatus::Paid, 'paid_at' => '2026-12-05', 'paid_amount' => '200.00',
        ]);
        // A fatura paga tem as compras que a formaram (o total é sempre a soma delas).
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create([
            'category_id' => $categoria->id, 'subcategory_id' => $sub->id, 'amount' => '200.00', 'expense_date' => '2026-12-01',
            'credit_card_id' => $cartao->id, 'credit_card_invoice_id' => $fatura->id,
        ]);

        $tela = $this->despesaNoCartao($cartao, '100.00', '2026-12-10')
            ->assertSee('A fatura de dezembro de 2026 já está paga. Este lançamento será somado ao total dela, e o valor pago continua o mesmo.');

        $tela->set('expenseDescription', 'Compra atrasada')->set('expenseNecessity', 'essential')
            ->set('expenseCategoryId', $categoria->id)->set('expenseSubcategoryId', $sub->id)
            ->call('saveExpense')->assertHasNoErrors();

        // O que o aviso promete é o que acontece: o total sobe, o valor pago fica.
        $fatura->refresh();
        $this->assertSame('300.00', $fatura->total_amount);
        $this->assertSame('200.00', $fatura->paid_amount);
        $this->assertSame(InvoiceStatus::Paid, $fatura->status);
    }

    public function test_despesa_em_conta_mostra_o_saldo_antes_e_depois(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => '1000.00']);

        Livewire::test(CashFlowIndex::class)
            ->call('toggleExpenseForm')
            ->set('expenseAmount', '123.45')
            ->set('expenseDate', '2026-12-10')
            ->set('expenseBankAccountId', $conta->id)
            ->assertSee('passa de R$ 1.000,00 para R$ 876,55 ao salvar, mesmo com data passada.');
    }

    public function test_estorno_em_conta_mostra_o_saldo_subindo(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => '1000.00']);

        Livewire::test(CashFlowIndex::class)
            ->call('toggleExpenseForm')
            ->set('expenseIsRefund', true)
            ->set('expenseAmount', '123.45')
            ->set('expenseDate', '2026-12-10')
            ->set('expenseBankAccountId', $conta->id)
            ->assertSee('passa de R$ 1.000,00 para R$ 1.123,45');
    }

    public function test_lancamento_de_outro_mes_avisa_onde_ele_vai_aparecer(): void
    {
        Livewire::test(CashFlowIndex::class)
            ->call('toggleExpenseForm')
            ->set('expenseAmount', '50.00')
            ->set('expenseDate', '2027-01-05')
            ->assertSee('Este lançamento aparecerá em janeiro de 2027, e não em dezembro de 2026.');
    }

    public function test_sem_valor_nao_ha_aviso(): void
    {
        $this->despesaNoCartao($this->cartao(), '', '2026-12-10')->assertDontSee('Ao salvar');
    }

    public function test_ao_editar_nao_ha_aviso(): void
    {
        [$categoria, $sub] = $this->categoria();
        $despesa = ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['category_id' => $categoria->id, 'subcategory_id' => $sub->id, 'expense_date' => '2026-12-10', 'amount' => '80.00']);

        Livewire::test(CashFlowIndex::class)->call('editExpense', $despesa->id)->assertDontSee('Ao salvar');
    }

    public function test_receita_em_conta_mostra_o_saldo_subindo(): void
    {
        $conta = BankAccount::factory()->for($this->perfil, 'profile')->for($this->membro, 'member')->create(['current_balance' => '1000.00']);

        Livewire::test(CashFlowIndex::class)
            ->call('toggleIncomeForm')
            ->set('incomeAmount', '500.00')
            ->set('incomeDate', '2026-12-10')
            ->set('incomeBankAccountId', $conta->id)
            ->assertSee('passa de R$ 1.000,00 para R$ 1.500,00 ao salvar');
    }

    public function test_dica_de_privacidade_diz_quem_vai_ver_e_a_de_recorrente_nao_promete_repeticao(): void
    {
        $bruno = User::factory()->create();
        ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $bruno->id, 'name' => 'Bruno', 'role' => MemberRole::Secondary]);

        Livewire::test(CashFlowIndex::class)
            ->call('toggleExpenseForm')
            ->set('expenseMemberId', $this->membro->id)
            ->assertSee('Só Ana e o consultor verão este lançamento.')
            ->assertSee('Sem membro, a despesa é da família. Em compra no cartão, vale o dono do cartão.')
            ->assertSee('Estorno ou cashback')
            ->assertDontSee('Estorno / cashback')
            ->call('toggleIncomeForm')
            ->assertSee('Para repetir todo mês, use Contas fixas.');
    }

    public function test_cartao_nao_escolhido_mostra_mensagem_em_portugues(): void
    {
        Livewire::test(CashFlowIndex::class)
            ->call('toggleExpenseForm')
            ->set('expensePaymentMethod', 'cartao')
            ->set('expenseDescription', 'Compra')
            ->set('expenseAmount', '10.00')
            ->set('expenseDate', '2026-12-10')
            ->set('expenseNecessity', 'essential')
            ->call('saveExpense')
            ->assertSee('Escolha o cartão da compra.')
            ->assertDontSee('expense payment method');
    }
}
