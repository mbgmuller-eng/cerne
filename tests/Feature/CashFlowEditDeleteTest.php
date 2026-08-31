<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Enums\Necessity;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Editar/excluir lançamento precisa manter o saldo da conta correto — o
 * ponto mais fácil de acertar por acidente é a ORDEM (desfazer o efeito
 * antigo antes de aplicar o novo). Despesa de cartão com fatura paga é
 * bloqueada de propósito: mexer no valor descombinaria o que já foi
 * debitado (ver CashFlowIndex::isLockedByPaidInvoice).
 */
class CashFlowEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_editar_despesa_vinculada_a_conta_ajusta_o_saldo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '1000.00']);
        $categoria = ExpenseCategory::factory()->create();
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'amount' => '100.00',
            'bank_account_id' => $conta->id,
            'category_id' => $categoria->id,
        ]);
        $conta->applyToBalance('-100.00'); // simula o débito original já aplicado

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $despesa->id)
            ->assertSet('expenseAmount', '100.00')
            ->set('expenseAmount', '250.00')
            ->call('saveExpense')
            ->assertHasNoErrors();

        self::assertSame('250.00', $despesa->fresh()->amount);
        self::assertSame('750.00', $conta->fresh()->current_balance); // 900 - 250 + 100... => 1000 - 100 + 100 - 250
    }

    public function test_editar_despesa_trocando_de_conta_ajusta_as_duas(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $contaAntiga = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '500.00']);
        $contaNova = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '2000.00']);
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'amount' => '100.00',
            'bank_account_id' => $contaAntiga->id,
        ]);

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $despesa->id)
            ->set('expenseBankAccountId', $contaNova->id)
            ->call('saveExpense')
            ->assertHasNoErrors();

        self::assertSame($contaNova->id, $despesa->fresh()->bank_account_id);
        self::assertSame('600.00', $contaAntiga->fresh()->current_balance); // devolveu os 100
        self::assertSame('1900.00', $contaNova->fresh()->current_balance); // debitou os 100
    }

    public function test_excluir_despesa_vinculada_a_conta_reverte_o_saldo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '400.00']);
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'amount' => '150.00',
            'bank_account_id' => $conta->id,
        ]);

        Livewire::test(CashFlowIndex::class)->call('deleteExpense', $despesa->id);

        self::assertNull(ExpenseRecord::withoutProfileScope()->find($despesa->id));
        self::assertSame('550.00', $conta->fresh()->current_balance);
    }

    public function test_editar_receita_ajusta_o_saldo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '1000.00']);
        $categoria = IncomeCategory::factory()->create();
        $receita = IncomeRecord::factory()->for($perfil, 'profile')->create([
            'amount' => '500.00',
            'bank_account_id' => $conta->id,
            'category_id' => $categoria->id,
        ]);

        Livewire::test(CashFlowIndex::class)
            ->call('editIncome', $receita->id)
            ->set('incomeAmount', '800.00')
            ->call('saveIncome')
            ->assertHasNoErrors();

        self::assertSame('800.00', $receita->fresh()->amount);
        self::assertSame('1300.00', $conta->fresh()->current_balance); // 1000 - 500 + 800
    }

    public function test_excluir_receita_reverte_o_saldo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '1000.00']);
        $receita = IncomeRecord::factory()->for($perfil, 'profile')->create([
            'amount' => '300.00',
            'bank_account_id' => $conta->id,
        ]);

        Livewire::test(CashFlowIndex::class)->call('deleteIncome', $receita->id);

        self::assertNull(IncomeRecord::withoutProfileScope()->find($receita->id));
        self::assertSame('700.00', $conta->fresh()->current_balance);
    }

    public function test_despesa_de_cartao_com_fatura_paga_nao_pode_ser_editada_nem_excluida(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        [$despesa, $fatura] = $this->criarDespesaDeCartao($perfil, InvoiceStatus::Paid);

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $despesa->id)
            ->assertSet('editingExpenseId', null); // não abriu o form de edição

        Livewire::test(CashFlowIndex::class)->call('deleteExpense', $despesa->id);

        self::assertNotNull(ExpenseRecord::withoutProfileScope()->find($despesa->id));
        self::assertSame('200.00', $fatura->fresh()->total_amount);
    }

    public function test_despesa_de_cartao_com_fatura_nao_paga_pode_ser_editada_e_recalcula_a_fatura(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        [$despesa, $fatura] = $this->criarDespesaDeCartao($perfil, InvoiceStatus::Closed);

        Livewire::test(CashFlowIndex::class)
            ->call('editExpense', $despesa->id)
            ->assertSet('editingExpenseId', $despesa->id)
            ->set('expenseAmount', '350.00')
            ->call('saveExpense')
            ->assertHasNoErrors();

        self::assertSame('350.00', $despesa->fresh()->amount);
        self::assertSame('350.00', $fatura->fresh()->total_amount);
    }

    public function test_despesa_de_cartao_com_fatura_nao_paga_pode_ser_excluida_e_recalcula_a_fatura(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        [$despesa, $fatura] = $this->criarDespesaDeCartao($perfil, InvoiceStatus::Closed);

        Livewire::test(CashFlowIndex::class)->call('deleteExpense', $despesa->id);

        self::assertNull(ExpenseRecord::withoutProfileScope()->find($despesa->id));
        self::assertSame('0.00', $fatura->fresh()->total_amount);
    }

    /** @return array{0: ExpenseRecord, 1: CreditCardInvoice} */
    private function criarDespesaDeCartao(FinancialProfile $perfil, InvoiceStatus $status): array
    {
        $cartao = CreditCard::factory()->for($perfil, 'profile')->create();
        $fatura = CreditCardInvoice::create([
            'profile_id' => $perfil->id,
            'credit_card_id' => $cartao->id,
            'year' => 2026,
            'month' => 8,
            'closing_date' => '2026-08-20',
            'due_date' => '2026-08-27',
            'total_amount' => '200.00',
            'status' => $status,
            'paid_at' => $status === InvoiceStatus::Paid ? now() : null,
            'paid_amount' => $status === InvoiceStatus::Paid ? '200.00' : null,
        ]);

        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'amount' => '200.00',
            'necessity' => Necessity::Essential,
            'bank_account_id' => null,
            'credit_card_id' => $cartao->id,
            'credit_card_invoice_id' => $fatura->id,
        ]);

        return [$despesa, $fatura];
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}
