<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\MemberRole;
use App\Livewire\Accounts\InvoiceShow;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * InvoiceService::pay() existia há tempos mas nunca esteve ligado a uma
 * tela — este é o primeiro teste que exercita o ciclo completo pagar →
 * estornar → editar a despesa → pagar de novo.
 */
class InvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagar_fatura_debita_a_conta_e_marca_paga(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '1000.00']);
        $fatura = $this->criarFatura($perfil, InvoiceStatus::Closed, '300.00');

        Livewire::test(InvoiceShow::class, ['invoice' => $fatura])
            ->set('payBankAccountId', $conta->id)
            ->set('payAmount', '300.00')
            ->call('pay')
            ->assertHasNoErrors();

        self::assertSame(InvoiceStatus::Paid, $fatura->fresh()->status);
        self::assertSame('300.00', $fatura->fresh()->paid_amount);
        self::assertSame('700.00', $conta->fresh()->current_balance);
    }

    public function test_estornar_fatura_paga_credita_de_volta_e_reabre(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '1000.00']);
        $fatura = $this->criarFatura($perfil, InvoiceStatus::Closed, '300.00');
        app(InvoiceService::class)->pay($fatura, $conta, '300.00');
        self::assertSame('700.00', $conta->fresh()->current_balance);

        Livewire::test(InvoiceShow::class, ['invoice' => $fatura->fresh()])
            ->call('unpay')
            ->assertHasNoErrors();

        $fatura->refresh();
        self::assertSame(InvoiceStatus::Closed, $fatura->status);
        self::assertNull($fatura->paid_at);
        self::assertNull($fatura->paid_amount);
        self::assertSame('1000.00', $conta->fresh()->current_balance);
    }

    public function test_estornar_fatura_nao_paga_e_bloqueado(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $fatura = $this->criarFatura($perfil, InvoiceStatus::Closed, '300.00');

        $this->expectException(RuntimeException::class);

        app(InvoiceService::class)->unpay($fatura);
    }

    public function test_apos_estornar_edita_a_despesa_e_o_total_recalcula_depois_paga_de_novo(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $conta = BankAccount::factory()->for($perfil, 'profile')->create(['current_balance' => '1000.00']);
        $fatura = $this->criarFatura($perfil, InvoiceStatus::Closed, '300.00');
        $cartao = $fatura->creditCard;
        $despesa = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'amount' => '300.00',
            'bank_account_id' => null,
            'credit_card_id' => $cartao->id,
            'credit_card_invoice_id' => $fatura->id,
        ]);

        app(InvoiceService::class)->pay($fatura, $conta, '300.00');

        // Estorna
        Livewire::test(InvoiceShow::class, ['invoice' => $fatura->fresh()])->call('unpay');
        self::assertSame(InvoiceStatus::Closed, $fatura->fresh()->status);

        // Agora edita a despesa (a fatura não está mais paga)
        \Livewire\Livewire::test(\App\Livewire\CashFlow\CashFlowIndex::class)
            ->call('editExpense', $despesa->id)
            ->set('expenseAmount', '450.00')
            ->call('saveExpense')
            ->assertHasNoErrors();

        self::assertSame('450.00', $fatura->fresh()->total_amount);

        // Paga de novo com o total corrigido
        Livewire::test(InvoiceShow::class, ['invoice' => $fatura->fresh()])
            ->set('payBankAccountId', $conta->id)
            ->set('payAmount', $fatura->fresh()->total_amount)
            ->call('pay')
            ->assertHasNoErrors();

        self::assertSame(InvoiceStatus::Paid, $fatura->fresh()->status);
        self::assertSame('550.00', $conta->fresh()->current_balance); // 1000 - 450
    }

    private function criarFatura(FinancialProfile $perfil, InvoiceStatus $status, string $total): CreditCardInvoice
    {
        $cartao = CreditCard::factory()->for($perfil, 'profile')->create();

        return CreditCardInvoice::create([
            'profile_id' => $perfil->id,
            'credit_card_id' => $cartao->id,
            'year' => 2026,
            'month' => 8,
            'closing_date' => '2026-08-20',
            'due_date' => '2026-08-27',
            'total_amount' => $total,
            'status' => $status,
        ]);
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
