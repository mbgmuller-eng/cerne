<?php

namespace App\Livewire\Accounts;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Support\Money;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 4 — Contas & Cartões.
 *
 * Os escopos globais já entregam apenas o que o membro logado pode ver;
 * esta tela não precisa (e não deve) filtrar de novo por privacidade.
 */
#[Layout('components.layouts.app')]
class AccountsIndex extends Component
{
    public function mount(): void
    {
        abort_if(app(ProfileContext::class)->profile() === null, 404);
    }

    /** @return Collection<int, BankAccount> */
    public function getAccountsProperty(): Collection
    {
        return BankAccount::query()->active()->with('member')->orderBy('bank_name')->get();
    }

    /** @return Collection<int, CreditCard> */
    public function getCardsProperty(): Collection
    {
        return CreditCard::query()->active()->with('member')->orderBy('card_name')->get();
    }

    /**
     * Fatura corrente de cada cartão, indexada pelo id do cartão.
     *
     * @return Collection<string, CreditCardInvoice>
     */
    public function getCurrentInvoicesProperty(): Collection
    {
        return CreditCardInvoice::query()
            ->outstanding()
            ->whereIn('credit_card_id', $this->cards->pluck('id'))
            ->orderBy('due_date')
            ->get()
            ->groupBy('credit_card_id')
            ->map(fn (Collection $invoices) => $invoices->first());
    }

    /** Somatório das contas marcadas como parte do consolidado. */
    public function getTotalBalanceProperty(): string
    {
        return Money::sum($this->accounts->where('included_in_consolidated', true)->pluck('current_balance'));
    }

    /** Total em aberto nos cartões — dívida, não patrimônio. */
    public function getTotalCardDebtProperty(): string
    {
        return Money::sum($this->currentInvoices->pluck('total_amount'));
    }

    public function render()
    {
        return view('livewire.accounts.accounts-index', [
            'accounts' => $this->accounts,
            'cards' => $this->cards,
            'currentInvoices' => $this->currentInvoices,
            'totalBalance' => $this->totalBalance,
            'totalCardDebt' => $this->totalCardDebt,
        ]);
    }
}
