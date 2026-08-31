<?php

namespace App\Livewire\Accounts;

use App\Enums\AccountType;
use App\Enums\CardBrand;
use App\Livewire\Concerns\HasPrivacyTabs;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\ProfileMember;
use App\Support\KnownBanks;
use App\Support\Money;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 4 — Contas & Cartões.
 *
 * Os escopos globais já entregam apenas o que o membro logado pode ver;
 * esta tela não precisa (e não deve) filtrar de novo por privacidade.
 *
 * Excluir só é permitido sem lançamento nenhum vinculado (ver
 * BankAccount::hasActivity()/CreditCard::hasActivity()) — com histórico,
 * a ação vira desativar, que tira a conta/cartão de novos lançamentos sem
 * apagar o que já aconteceu.
 */
#[Layout('components.layouts.app')]
class AccountsIndex extends Component
{
    use RequiresActiveProfile;
    use HasPrivacyTabs;

    protected function privacyModels(): array
    {
        return [BankAccount::class, CreditCard::class];
    }

    // -----------------------------------------------------------------
    // Formulário — Conta bancária
    // -----------------------------------------------------------------

    public bool $showAccountForm = false;

    public ?string $editingAccountId = null;

    public string $accountBankName = '';

    public string $accountType = 'checking';

    public string $accountAgency = '';

    public string $accountNumber = '';

    public string $accountBalance = '0';

    public string $accountMemberId = '';

    public bool $accountIsJoint = false;

    public bool $accountVisibleToPartner = true;

    public bool $accountIncludedInConsolidated = true;

    public string $accountColor = '#0F766E';

    public string $accountNotes = '';

    public ?string $confirmingDeleteAccountId = null;

    // -----------------------------------------------------------------
    // Formulário — Cartão de crédito
    // -----------------------------------------------------------------

    public bool $showCardForm = false;

    public ?string $editingCardId = null;

    public string $cardName = '';

    public string $cardBankName = '';

    public string $cardBrand = 'visa';

    public string $cardLimit = '';

    public string $cardClosingDay = '';

    public string $cardDueDay = '';

    public string $cardLastFour = '';

    public string $cardMemberId = '';

    public bool $cardIsJoint = false;

    public bool $cardVisibleToPartner = true;

    public bool $cardIncludedInConsolidated = true;

    public string $cardColor = '#B45309';

    public ?string $confirmingDeleteCardId = null;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
    }

    /**
     * Bateu num banco conhecido? Preenche a cor sozinha — só dispara em
     * digitação de verdade (wire:model.live), nunca quando editAccount()
     * carrega um valor já salvo, então não sobrescreve uma cor que a
     * pessoa escolheu de propósito.
     */
    public function updatedAccountBankName(string $value): void
    {
        $cor = KnownBanks::colorFor($value);

        if ($cor !== null) {
            $this->accountColor = $cor;
        }
    }

    public function updatedCardBankName(string $value): void
    {
        $cor = KnownBanks::colorFor($value);

        if ($cor !== null) {
            $this->cardColor = $cor;
        }
    }

    // -----------------------------------------------------------------
    // Conta bancária — cadastrar / editar / excluir / desativar
    // -----------------------------------------------------------------

    public function toggleAccountForm(): void
    {
        $this->showAccountForm = ! $this->showAccountForm;
        $this->showCardForm = false;

        if ($this->showAccountForm) {
            $this->resetAccountForm();
        }
    }

    public function editAccount(string $accountId): void
    {
        $account = BankAccount::query()->findOrFail($accountId);

        $this->editingAccountId = $account->id;
        $this->accountBankName = $account->bank_name;
        $this->accountType = $account->account_type->value;
        $this->accountAgency = (string) $account->agency;
        $this->accountNumber = (string) $account->account_number;
        $this->accountBalance = $account->current_balance;
        $this->accountMemberId = (string) $account->member_id;
        $this->accountIsJoint = $account->is_joint;
        $this->accountVisibleToPartner = $account->visible_to_partner;
        $this->accountIncludedInConsolidated = $account->included_in_consolidated;
        $this->accountColor = $account->color_hex ?? '#0F766E';
        $this->accountNotes = (string) $account->notes;
        $this->showAccountForm = true;
        $this->showCardForm = false;
    }

    public function saveAccount(): void
    {
        $data = $this->validate([
            'accountBankName' => ['required', 'string', 'max:255'],
            'accountType' => ['required', Rule::enum(AccountType::class)],
            'accountAgency' => ['nullable', 'string', 'max:20'],
            'accountNumber' => ['nullable', 'string', 'max:30'],
            'accountBalance' => ['required', 'numeric'],
            'accountMemberId' => ['required'],
            'accountColor' => ['required', 'string', 'max:7'],
            'accountNotes' => ['nullable', 'string'],
        ], attributes: [
            'accountBankName' => 'banco',
            'accountType' => 'tipo',
            'accountBalance' => 'saldo',
            'accountMemberId' => 'membro',
        ]);

        $memberId = $this->resolveMembro($data['accountMemberId'], 'accountMemberId');

        $payload = [
            'bank_name' => $data['accountBankName'],
            'account_type' => $data['accountType'],
            'agency' => $data['accountAgency'] !== '' ? $data['accountAgency'] : null,
            'account_number' => $data['accountNumber'] !== '' ? $data['accountNumber'] : null,
            'current_balance' => $data['accountBalance'],
            'member_id' => $memberId,
            'is_joint' => $this->accountIsJoint,
            'visible_to_partner' => $this->accountVisibleToPartner,
            'included_in_consolidated' => $this->accountIncludedInConsolidated,
            'color_hex' => $data['accountColor'],
            'notes' => $data['accountNotes'] !== '' ? $data['accountNotes'] : null,
        ];

        if ($this->editingAccountId !== null) {
            BankAccount::query()->findOrFail($this->editingAccountId)->update($payload);
            session()->flash('status', 'Conta atualizada.');
        } else {
            BankAccount::create($payload + ['is_active' => true]);
            session()->flash('status', 'Conta cadastrada.');
        }

        $this->resetAccountForm();
        $this->showAccountForm = false;
    }

    /**
     * Sem lançamento vinculado (BankAccount::hasActivity()): exclui de
     * verdade. Com lançamento: só desativa — excluir apagaria o rastro do
     * que já aconteceu, e nem o usuário pediu isso, pediu "não deixar
     * lançar de novo".
     */
    public function confirmDeleteAccount(string $accountId): void
    {
        $this->confirmingDeleteAccountId = $accountId;
    }

    public function cancelDeleteAccount(): void
    {
        $this->confirmingDeleteAccountId = null;
    }

    public function deleteAccount(string $accountId): void
    {
        $account = BankAccount::query()->findOrFail($accountId);

        if ($account->hasActivity()) {
            $account->update(['is_active' => false]);
            session()->flash('status', 'Essa conta tem lançamentos — desativada em vez de excluída.');
        } else {
            $account->delete();
            session()->flash('status', 'Conta excluída.');
        }

        $this->confirmingDeleteAccountId = null;
    }

    public function reactivateAccount(string $accountId): void
    {
        BankAccount::query()->findOrFail($accountId)->update(['is_active' => true]);
        session()->flash('status', 'Conta reativada.');
    }

    private function resetAccountForm(): void
    {
        $this->reset(
            'editingAccountId', 'accountBankName', 'accountAgency', 'accountNumber', 'accountMemberId',
            'accountIsJoint', 'accountNotes',
        );
        $this->accountType = 'checking';
        $this->accountBalance = '0';
        $this->accountVisibleToPartner = true;
        $this->accountIncludedInConsolidated = true;
        $this->accountColor = '#0F766E';
        $this->resetErrorBag();
    }

    // -----------------------------------------------------------------
    // Cartão — cadastrar / editar / excluir / desativar
    // -----------------------------------------------------------------

    public function toggleCardForm(): void
    {
        $this->showCardForm = ! $this->showCardForm;
        $this->showAccountForm = false;

        if ($this->showCardForm) {
            $this->resetCardForm();
        }
    }

    public function editCard(string $cardId): void
    {
        $card = CreditCard::query()->findOrFail($cardId);

        $this->editingCardId = $card->id;
        $this->cardName = $card->card_name;
        $this->cardBankName = $card->bank_name;
        $this->cardBrand = $card->card_brand->value;
        $this->cardLimit = $card->credit_limit;
        $this->cardClosingDay = (string) $card->closing_day;
        $this->cardDueDay = (string) $card->due_day;
        $this->cardLastFour = (string) $card->last_four_digits;
        $this->cardMemberId = (string) $card->member_id;
        $this->cardIsJoint = $card->is_joint;
        $this->cardVisibleToPartner = $card->visible_to_partner;
        $this->cardIncludedInConsolidated = $card->included_in_consolidated;
        $this->cardColor = $card->color_hex ?? '#B45309';
        $this->showCardForm = true;
        $this->showAccountForm = false;
    }

    public function saveCard(): void
    {
        $data = $this->validate([
            'cardName' => ['required', 'string', 'max:255'],
            'cardBankName' => ['required', 'string', 'max:255'],
            'cardBrand' => ['required', Rule::enum(CardBrand::class)],
            'cardLimit' => ['required', 'numeric', 'gt:0'],
            'cardClosingDay' => ['required', 'integer', 'between:1,31'],
            'cardDueDay' => ['required', 'integer', 'between:1,31'],
            'cardLastFour' => ['nullable', 'string', 'size:4'],
            'cardMemberId' => ['required'],
            'cardColor' => ['required', 'string', 'max:7'],
        ], attributes: [
            'cardName' => 'nome do cartão',
            'cardBankName' => 'banco',
            'cardBrand' => 'bandeira',
            'cardLimit' => 'limite',
            'cardClosingDay' => 'dia de fechamento',
            'cardDueDay' => 'dia de vencimento',
            'cardLastFour' => 'últimos 4 dígitos',
            'cardMemberId' => 'membro',
        ]);

        $memberId = $this->resolveMembro($data['cardMemberId'], 'cardMemberId');

        $payload = [
            'card_name' => $data['cardName'],
            'bank_name' => $data['cardBankName'],
            'card_brand' => $data['cardBrand'],
            'credit_limit' => $data['cardLimit'],
            'closing_day' => $data['cardClosingDay'],
            'due_day' => $data['cardDueDay'],
            'last_four_digits' => $data['cardLastFour'] !== '' ? $data['cardLastFour'] : null,
            'member_id' => $memberId,
            'is_joint' => $this->cardIsJoint,
            'visible_to_partner' => $this->cardVisibleToPartner,
            'included_in_consolidated' => $this->cardIncludedInConsolidated,
            'color_hex' => $data['cardColor'],
        ];

        if ($this->editingCardId !== null) {
            CreditCard::query()->findOrFail($this->editingCardId)->update($payload);
            session()->flash('status', 'Cartão atualizado.');
        } else {
            CreditCard::create($payload + ['is_active' => true]);
            session()->flash('status', 'Cartão cadastrado.');
        }

        $this->resetCardForm();
        $this->showCardForm = false;
    }

    public function confirmDeleteCard(string $cardId): void
    {
        $this->confirmingDeleteCardId = $cardId;
    }

    public function cancelDeleteCard(): void
    {
        $this->confirmingDeleteCardId = null;
    }

    public function deleteCard(string $cardId): void
    {
        $card = CreditCard::query()->findOrFail($cardId);

        if ($card->hasActivity()) {
            $card->update(['is_active' => false]);
            session()->flash('status', 'Esse cartão tem lançamentos — desativado em vez de excluído.');
        } else {
            $card->delete();
            session()->flash('status', 'Cartão excluído.');
        }

        $this->confirmingDeleteCardId = null;
    }

    public function reactivateCard(string $cardId): void
    {
        CreditCard::query()->findOrFail($cardId)->update(['is_active' => true]);
        session()->flash('status', 'Cartão reativado.');
    }

    private function resetCardForm(): void
    {
        $this->reset(
            'editingCardId', 'cardName', 'cardBankName', 'cardLimit', 'cardClosingDay',
            'cardDueDay', 'cardLastFour', 'cardMemberId', 'cardIsJoint',
        );
        $this->cardBrand = 'visa';
        $this->cardVisibleToPartner = true;
        $this->cardIncludedInConsolidated = true;
        $this->cardColor = '#B45309';
        $this->resetErrorBag();
    }

    // -----------------------------------------------------------------
    // Comum
    // -----------------------------------------------------------------

    /**
     * Conta/cartão SEMPRE tem um dono (member_id não é nullable, diferente
     * de FixedBill/RecurringIncome/ExpenseRecord — "conjunta" é a flag
     * is_joint, não a ausência de membro). ProfileMember não é
     * BelongsToProfile, então também é aqui que a tenancy é garantida: sem
     * essa checagem, um member_id de outro perfil passaria direto (mesmo
     * raciocínio já usado em CashFlowIndex e FixedBillsIndex).
     *
     * @param  'accountMemberId'|'cardMemberId'  $campo
     */
    private function resolveMembro(string $memberId, string $campo): string
    {
        $membro = ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('id', $memberId)
            ->first();

        if ($membro === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                $campo => 'Selecione um membro.',
            ]);
        }

        return $membro->id;
    }

    /** @return Collection<int, BankAccount> */
    public function getAccountsProperty(): Collection
    {
        $query = BankAccount::query()->active()->with('member')->orderBy('bank_name');
        $this->applyPrivacyTabFilter($query);

        return $query->get();
    }

    /** @return Collection<int, BankAccount> */
    public function getInactiveAccountsProperty(): Collection
    {
        $query = BankAccount::query()->where('is_active', false)->with('member')->orderBy('bank_name');
        $this->applyPrivacyTabFilter($query);

        return $query->get();
    }

    /** @return Collection<int, CreditCard> */
    public function getCardsProperty(): Collection
    {
        $query = CreditCard::query()->active()->with('member')->orderBy('card_name');
        $this->applyPrivacyTabFilter($query);

        return $query->get();
    }

    /** @return Collection<int, CreditCard> */
    public function getInactiveCardsProperty(): Collection
    {
        $query = CreditCard::query()->where('is_active', false)->with('member')->orderBy('card_name');
        $this->applyPrivacyTabFilter($query);

        return $query->get();
    }

    /**
     * Casal (is_joint) ou um membro específico — a aba de privacidade,
     * quando visível (ver HasPrivacyTabs). Conta/cartão sempre tem
     * member_id preenchido (diferente de despesa/receita) — "conjunta"
     * é a flag is_joint, não a ausência de dono.
     */
    private function applyPrivacyTabFilter(Builder $query): void
    {
        if (! $this->showPrivacyTabs) {
            return;
        }

        if ($this->viewAs === '') {
            $query->where('is_joint', true);
        } else {
            $query->where('member_id', $this->viewAs)->where('is_joint', false);
        }
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
        $profileId = app(ProfileContext::class)->profileId();

        return view('livewire.accounts.accounts-index', [
            'showPrivacyTabs' => $this->showPrivacyTabs,
            'privacyMembers' => $this->privacyMembers,
            'accounts' => $this->accounts,
            'inactiveAccounts' => $this->inactiveAccounts,
            'cards' => $this->cards,
            'inactiveCards' => $this->inactiveCards,
            'currentInvoices' => $this->currentInvoices,
            'totalBalance' => $this->totalBalance,
            'totalCardDebt' => $this->totalCardDebt,
            'members' => ProfileMember::query()
                ->where('profile_id', $profileId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'knownBanks' => KnownBanks::names(),
        ]);
    }
}
