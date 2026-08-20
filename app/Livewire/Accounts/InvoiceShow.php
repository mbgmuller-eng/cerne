<?php

namespace App\Livewire\Accounts;

use App\Models\CreditCardInvoice;
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

    public function mount(CreditCardInvoice $invoice): void
    {
        $this->invoice = $invoice->load('creditCard', 'paidFromAccount');
    }

    public function render()
    {
        return view('livewire.accounts.invoice-show', [
            'invoice' => $this->invoice,
            'card' => $this->invoice->creditCard,
        ]);
    }
}
