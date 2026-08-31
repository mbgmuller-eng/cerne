<?php

namespace App\Livewire\Accounts;

use App\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\CreditCardInvoice;
use App\Services\InvoiceService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Drill-down da tela 4: cartão → fatura → lançamentos.
 *
 * A fatura chega pelo escopo de tenancy (o model usa BelongsToProfile),
 * então uma fatura de outro perfil simplesmente não é encontrada — 404,
 * não 403, para não confirmar que o id existe.
 */
#[Layout('components.layouts.app')]
class InvoiceShow extends Component
{
    public CreditCardInvoice $invoice;

    public string $payBankAccountId = '';

    public string $payAmount = '';

    public bool $confirmingUnpay = false;

    public function mount(CreditCardInvoice $invoice): void
    {
        $this->invoice = $invoice->load('creditCard', 'paidFromAccount');
        $this->payAmount = $this->invoice->total_amount;
    }

    public function pay(InvoiceService $service): void
    {
        $data = $this->validate([
            'payBankAccountId' => ['required'],
            'payAmount' => ['required', 'numeric', 'gt:0'],
        ], attributes: [
            'payBankAccountId' => 'conta',
            'payAmount' => 'valor',
        ]);

        $conta = BankAccount::query()->findOrFail($data['payBankAccountId']);

        $service->pay($this->invoice, $conta, $data['payAmount']);

        $this->invoice = $this->invoice->fresh(['creditCard', 'paidFromAccount']);
        session()->flash('status', 'Fatura marcada como paga.');
    }

    public function unpay(InvoiceService $service): void
    {
        $service->unpay($this->invoice);

        $this->invoice = $this->invoice->fresh(['creditCard', 'paidFromAccount']);
        $this->payAmount = $this->invoice->total_amount;
        $this->confirmingUnpay = false;
        session()->flash('status', 'Pagamento estornado — a fatura voltou pra "fechada".');
    }

    public function render()
    {
        return view('livewire.accounts.invoice-show', [
            'invoice' => $this->invoice,
            'card' => $this->invoice->creditCard,
            // Aberta ainda está acumulando compra — não faz sentido pagar
            // antes de fechar (ver InvoiceStatus::acceptsNewExpenses()).
            'podePagar' => in_array($this->invoice->status, [InvoiceStatus::Closed, InvoiceStatus::Overdue], true),
            'bankAccounts' => BankAccount::query()->active()->orderBy('bank_name')->get(),
        ]);
    }
}
