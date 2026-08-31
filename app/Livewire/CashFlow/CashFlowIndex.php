<?php

namespace App\Livewire\CashFlow;

use App\Enums\InvoiceStatus;
use App\Enums\Necessity;
use App\Livewire\Concerns\HasPrivacyTabs;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseSubcategory;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Services\InstallmentService;
use App\Services\InvoiceService;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tela 3 — Fluxo de Caixa.
 *
 * Lançamentos do mês com filtros por categoria, necessidade e membro, e
 * cadastro manual de despesa/receita.
 *
 * O filtro por membro NÃO substitui a privacidade: os escopos globais já
 * removeram o que o usuário não pode ver antes de qualquer filtro chegar
 * aqui. Este é um recorte de leitura, não um controle de acesso.
 *
 * `member_id` é o único campo aceito do formulário que NÃO tem escopo de
 * perfil automático (ProfileMember não usa BelongsToProfile — ver o
 * comentário em validarMembro()); os demais (categoria, subcategoria,
 * conta, cartão) já falham fechado sozinhos porque os models são
 * BelongsToProfile/BelongsToProfileOrShared e um find() fora do perfil
 * ativo simplesmente não encontra nada.
 */
#[Layout('components.layouts.app')]
class CashFlowIndex extends Component
{
    use RequiresActiveProfile;
    use HasPrivacyTabs;

    protected function privacyModels(): array
    {
        return [ExpenseRecord::class, IncomeRecord::class];
    }

    /** Estado na URL: o mês visto sobrevive ao recarregar e é linkável. */
    #[Url]
    public ?int $year = null;

    #[Url]
    public ?int $month = null;

    #[Url]
    public string $necessity = '';

    #[Url]
    public string $categoryId = '';

    #[Url]
    public string $memberId = '';

    // -----------------------------------------------------------------
    // Formulário — Despesa
    // -----------------------------------------------------------------

    public bool $showExpenseForm = false;

    /** Nulo = criando; preenchido = editando esta despesa. */
    public ?string $editingExpenseId = null;

    public ?string $confirmingDeleteExpenseId = null;

    public string $expenseDescription = '';

    public string $expenseAmount = '';

    public ?string $expenseDate = null;

    public string $expenseNecessity = '';

    public string $expenseCategoryId = '';

    public string $expenseSubcategoryId = '';

    public string $expenseNewSubcategory = '';

    public string $expenseMemberId = '';

    public bool $expenseIsPrivate = false;

    /** 'outro' (conta bancária opcional) ou 'cartao' (via InstallmentService). */
    public string $expensePaymentMethod = 'outro';

    public string $expenseBankAccountId = '';

    public string $expenseCreditCardId = '';

    public int $expenseInstallments = 1;

    public string $expenseNotes = '';

    // -----------------------------------------------------------------
    // Formulário — Receita
    // -----------------------------------------------------------------

    public bool $showIncomeForm = false;

    /** Nulo = criando; preenchido = editando esta receita. */
    public ?string $editingIncomeId = null;

    public ?string $confirmingDeleteIncomeId = null;

    public string $incomeDescription = '';

    public string $incomeAmount = '';

    public ?string $incomeDate = null;

    public string $incomeCategoryId = '';

    public string $incomeMemberId = '';

    public bool $incomeIsPrivate = false;

    public string $incomeBankAccountId = '';

    public bool $incomeRecurring = false;

    public string $incomeNotes = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();

        $hoje = CarbonImmutable::now();
        $this->year ??= $hoje->year;
        $this->month ??= $hoje->month;
    }

    // -----------------------------------------------------------------
    // Navegação de período
    // -----------------------------------------------------------------

    public function previousMonth(): void
    {
        $anterior = $this->periodStart()->subMonth();
        $this->year = $anterior->year;
        $this->month = $anterior->month;
    }

    public function nextMonth(): void
    {
        $proximo = $this->periodStart()->addMonth();
        $this->year = $proximo->year;
        $this->month = $proximo->month;
    }

    public function clearFilters(): void
    {
        $this->reset('necessity', 'categoryId', 'memberId');
    }

    private function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1);
    }

    // -----------------------------------------------------------------
    // Formulário — Despesa
    // -----------------------------------------------------------------

    public function toggleExpenseForm(): void
    {
        $this->showExpenseForm = ! $this->showExpenseForm;
        $this->showIncomeForm = false;

        if ($this->showExpenseForm) {
            $this->resetExpenseForm();
        }
    }

    /** @return Collection<int, ExpenseSubcategory> */
    public function getExpenseSubcategoriesProperty(): Collection
    {
        if ($this->expenseCategoryId === '') {
            return collect();
        }

        return ExpenseSubcategory::query()
            ->where('category_id', $this->expenseCategoryId)
            ->available()
            ->get();
    }

    public function editExpense(string $id): void
    {
        $despesa = ExpenseRecord::query()->findOrFail($id);

        if ($this->isLockedByPaidInvoice($despesa)) {
            session()->flash('status', 'Essa despesa está numa fatura já paga — estorne o pagamento da fatura antes de editar.');

            return;
        }

        $this->editingExpenseId = $despesa->id;
        $this->expenseDescription = $despesa->description;
        $this->expenseAmount = $despesa->amount;
        $this->expenseDate = $despesa->expense_date->toDateString();
        $this->expenseNecessity = $despesa->necessity->value;
        $this->expenseCategoryId = $despesa->category_id;
        $this->expenseSubcategoryId = $despesa->subcategory_id ?? '';
        $this->expenseMemberId = $despesa->member_id ?? '';
        $this->expenseIsPrivate = $despesa->is_private;
        $this->expensePaymentMethod = $despesa->credit_card_id !== null ? 'cartao' : 'outro';
        $this->expenseBankAccountId = $despesa->bank_account_id ?? '';
        $this->expenseNotes = $despesa->notes ?? '';
        $this->showExpenseForm = true;
        $this->showIncomeForm = false;
    }

    public function confirmDeleteExpense(string $id): void
    {
        $this->confirmingDeleteExpenseId = $id;
    }

    public function cancelDeleteExpense(): void
    {
        $this->confirmingDeleteExpenseId = null;
    }

    public function deleteExpense(string $id): void
    {
        $despesa = ExpenseRecord::query()->findOrFail($id);

        if ($this->isLockedByPaidInvoice($despesa)) {
            $this->confirmingDeleteExpenseId = null;
            session()->flash('status', 'Essa despesa está numa fatura já paga — estorne o pagamento da fatura antes de excluir.');

            return;
        }

        DB::transaction(function () use ($despesa): void {
            if ($despesa->bank_account_id !== null) {
                BankAccount::withoutProfileScope()->find($despesa->bank_account_id)?->applyToBalance($despesa->amount);
            }

            $invoice = $despesa->invoice;
            $despesa->delete();

            if ($invoice !== null) {
                app(InvoiceService::class)->recalculateTotal($invoice);
            }
        });

        $this->confirmingDeleteExpenseId = null;
        session()->flash('status', 'Despesa excluída.');
    }

    /** Despesa de cartão numa fatura já paga: mexer no valor descombinaria o que já foi debitado. */
    private function isLockedByPaidInvoice(ExpenseRecord $despesa): bool
    {
        return $despesa->credit_card_id !== null && $despesa->invoice?->status === InvoiceStatus::Paid;
    }

    public function saveExpense(InstallmentService $installments): void
    {
        if ($this->editingExpenseId !== null) {
            $this->updateExpense();

            return;
        }

        $data = $this->validate([
            'expenseDescription' => ['required', 'string', 'max:255'],
            'expenseAmount' => ['required', 'numeric', 'gt:0'],
            'expenseDate' => ['required', 'date'],
            'expenseNecessity' => ['required', Rule::enum(Necessity::class)],
            'expenseCategoryId' => ['required'],
            'expenseSubcategoryId' => ['nullable'],
            'expenseNewSubcategory' => ['nullable', 'string', 'max:255'],
            'expenseMemberId' => ['nullable'],
            'expensePaymentMethod' => ['required', Rule::in(['outro', 'cartao'])],
            'expenseBankAccountId' => ['nullable'],
            'expenseCreditCardId' => ['required_if:expensePaymentMethod,cartao'],
            'expenseInstallments' => ['required_if:expensePaymentMethod,cartao', 'integer', 'min:1', 'max:'.config('cerne.installments.max')],
            'expenseNotes' => ['nullable', 'string'],
        ], attributes: [
            'expenseDescription' => 'descrição',
            'expenseAmount' => 'valor',
            'expenseDate' => 'data',
            'expenseNecessity' => 'necessidade',
            'expenseCategoryId' => 'categoria',
            'expenseCreditCardId' => 'cartão',
            'expenseInstallments' => 'parcelas',
        ]);

        // find() dentro do escopo do perfil ativo: se o category_id veio
        // adulterado (outro perfil), simplesmente não existe aqui — falha
        // fechado por conta do BelongsToProfileOrShared, não por checagem
        // manual.
        $categoria = ExpenseCategory::query()->findOrFail($data['expenseCategoryId']);
        $subcategoriaId = $this->resolveSubcategoryId($categoria);
        $memberId = $this->validarMembro($this->expenseMemberId);
        $data_compra = CarbonImmutable::parse($data['expenseDate']);

        if ($data['expensePaymentMethod'] === 'cartao') {
            $cartao = CreditCard::query()->findOrFail($data['expenseCreditCardId']);

            $installments->create($cartao, [
                'description' => $data['expenseDescription'],
                'total_amount' => $data['expenseAmount'],
                'installments' => (int) $data['expenseInstallments'],
                'purchase_date' => $data_compra,
                'necessity' => Necessity::from($data['expenseNecessity']),
                'category_id' => $categoria->id,
                'subcategory_id' => $subcategoriaId,
                'member_id' => $memberId,
                'notes' => $this->expenseNotes !== '' ? $this->expenseNotes : null,
                'is_private' => $memberId !== null && $this->expenseIsPrivate,
            ], auth()->id());
        } else {
            $conta = $this->expenseBankAccountId !== ''
                ? BankAccount::query()->findOrFail($this->expenseBankAccountId)
                : null;

            ExpenseRecord::create([
                'member_id' => $memberId,
                'description' => $data['expenseDescription'],
                'necessity' => $data['expenseNecessity'],
                'category_id' => $categoria->id,
                'subcategory_id' => $subcategoriaId,
                'amount' => $data['expenseAmount'],
                'expense_date' => $data_compra,
                'bank_account_id' => $conta?->id,
                'notes' => $this->expenseNotes !== '' ? $this->expenseNotes : null,
                'created_by_user_id' => auth()->id(),
                'is_private' => $memberId !== null && $this->expenseIsPrivate,
            ]);

            $conta?->applyToBalance('-'.$data['expenseAmount']);
        }

        session()->flash('status', 'Despesa adicionada.');
        $this->resetExpenseForm();
        $this->showExpenseForm = false;
    }

    /**
     * Não troca a despesa entre cartão e conta bancária — é uma mudança
     * estrutural (fatura, parcelamento) que este formulário não cobre.
     * Só os demais campos mudam; conta bancária pode ser reatribuída
     * quando a despesa não é de cartão.
     */
    private function updateExpense(): void
    {
        $despesa = ExpenseRecord::query()->findOrFail($this->editingExpenseId);

        if ($this->isLockedByPaidInvoice($despesa)) {
            $this->addError('expenseAmount', 'Essa despesa está numa fatura já paga — estorne o pagamento da fatura antes de editar.');

            return;
        }

        $data = $this->validate([
            'expenseDescription' => ['required', 'string', 'max:255'],
            'expenseAmount' => ['required', 'numeric', 'gt:0'],
            'expenseDate' => ['required', 'date'],
            'expenseNecessity' => ['required', Rule::enum(Necessity::class)],
            'expenseCategoryId' => ['required'],
            'expenseSubcategoryId' => ['nullable'],
            'expenseNewSubcategory' => ['nullable', 'string', 'max:255'],
            'expenseMemberId' => ['nullable'],
            'expenseBankAccountId' => ['nullable'],
            'expenseNotes' => ['nullable', 'string'],
        ], attributes: [
            'expenseDescription' => 'descrição',
            'expenseAmount' => 'valor',
            'expenseDate' => 'data',
            'expenseNecessity' => 'necessidade',
            'expenseCategoryId' => 'categoria',
        ]);

        $categoria = ExpenseCategory::query()->findOrFail($data['expenseCategoryId']);
        $subcategoriaId = $this->resolveSubcategoryId($categoria);
        $memberId = $this->validarMembro($this->expenseMemberId);
        $data_compra = CarbonImmutable::parse($data['expenseDate']);

        DB::transaction(function () use ($despesa, $data, $categoria, $subcategoriaId, $memberId, $data_compra): void {
            $camposComuns = [
                'description' => $data['expenseDescription'],
                'necessity' => $data['expenseNecessity'],
                'category_id' => $categoria->id,
                'subcategory_id' => $subcategoriaId,
                'amount' => $data['expenseAmount'],
                'expense_date' => $data_compra,
                'member_id' => $memberId,
                'notes' => $this->expenseNotes !== '' ? $this->expenseNotes : null,
                'is_private' => $memberId !== null && $this->expenseIsPrivate,
            ];

            if ($despesa->credit_card_id !== null) {
                $despesa->update($camposComuns);

                if ($despesa->invoice !== null) {
                    app(InvoiceService::class)->recalculateTotal($despesa->invoice);
                }

                return;
            }

            // Desfaz o débito antigo ANTES de gravar o novo valor/conta —
            // se as duas coisas fossem a mesma conta, a ordem errada
            // aplicaria o delta sobre um saldo que já mudou.
            if ($despesa->bank_account_id !== null) {
                BankAccount::withoutProfileScope()->find($despesa->bank_account_id)?->applyToBalance($despesa->amount);
            }

            $contaNova = $this->expenseBankAccountId !== ''
                ? BankAccount::query()->findOrFail($this->expenseBankAccountId)
                : null;

            $despesa->update($camposComuns + ['bank_account_id' => $contaNova?->id]);

            $contaNova?->applyToBalance('-'.$data['expenseAmount']);
        });

        session()->flash('status', 'Despesa atualizada.');
        $this->resetExpenseForm();
        $this->showExpenseForm = false;
    }

    private function resolveSubcategoryId(ExpenseCategory $categoria): ?string
    {
        if (trim($this->expenseNewSubcategory) !== '') {
            return ExpenseSubcategory::createCustom($categoria, $this->expenseNewSubcategory)->id;
        }

        if ($this->expenseSubcategoryId === '') {
            return null;
        }

        // Mesmo raciocínio de falha fechada: subcategoria também é
        // BelongsToProfileOrShared.
        return ExpenseSubcategory::query()->findOrFail($this->expenseSubcategoryId)->id;
    }

    private function resetExpenseForm(): void
    {
        $this->reset(
            'expenseDescription', 'expenseAmount', 'expenseNecessity', 'expenseCategoryId',
            'expenseSubcategoryId', 'expenseNewSubcategory', 'expenseMemberId', 'expenseIsPrivate',
            'expensePaymentMethod', 'expenseBankAccountId', 'expenseCreditCardId', 'expenseInstallments',
            'expenseNotes', 'editingExpenseId',
        );
        $this->expenseDate = CarbonImmutable::now()->toDateString();
        $this->expensePaymentMethod = 'outro';
        $this->expenseInstallments = 1;
        $this->resetErrorBag();
    }

    // -----------------------------------------------------------------
    // Formulário — Receita
    // -----------------------------------------------------------------

    public function toggleIncomeForm(): void
    {
        $this->showIncomeForm = ! $this->showIncomeForm;
        $this->showExpenseForm = false;

        if ($this->showIncomeForm) {
            $this->resetIncomeForm();
        }
    }

    public function editIncome(string $id): void
    {
        $receita = IncomeRecord::query()->findOrFail($id);

        $this->editingIncomeId = $receita->id;
        $this->incomeDescription = $receita->description ?? '';
        $this->incomeAmount = $receita->amount;
        $this->incomeDate = $receita->received_date->toDateString();
        $this->incomeCategoryId = $receita->category_id;
        $this->incomeMemberId = $receita->member_id ?? '';
        $this->incomeIsPrivate = $receita->is_private;
        $this->incomeBankAccountId = $receita->bank_account_id ?? '';
        $this->incomeRecurring = $receita->is_recurring;
        $this->incomeNotes = $receita->notes ?? '';
        $this->showIncomeForm = true;
        $this->showExpenseForm = false;
    }

    public function confirmDeleteIncome(string $id): void
    {
        $this->confirmingDeleteIncomeId = $id;
    }

    public function cancelDeleteIncome(): void
    {
        $this->confirmingDeleteIncomeId = null;
    }

    public function deleteIncome(string $id): void
    {
        $receita = IncomeRecord::query()->findOrFail($id);

        DB::transaction(function () use ($receita): void {
            if ($receita->bank_account_id !== null) {
                BankAccount::withoutProfileScope()->find($receita->bank_account_id)?->applyToBalance('-'.$receita->amount);
            }

            $receita->delete();
        });

        $this->confirmingDeleteIncomeId = null;
        session()->flash('status', 'Receita excluída.');
    }

    public function saveIncome(): void
    {
        if ($this->editingIncomeId !== null) {
            $this->updateIncome();

            return;
        }

        $data = $this->validate([
            'incomeDescription' => ['nullable', 'string', 'max:255'],
            'incomeAmount' => ['required', 'numeric', 'gt:0'],
            'incomeDate' => ['required', 'date'],
            'incomeCategoryId' => ['required'],
            'incomeMemberId' => ['nullable'],
            'incomeBankAccountId' => ['nullable'],
            'incomeNotes' => ['nullable', 'string'],
        ], attributes: [
            'incomeAmount' => 'valor',
            'incomeDate' => 'data',
            'incomeCategoryId' => 'categoria',
        ]);

        $categoria = IncomeCategory::query()->findOrFail($data['incomeCategoryId']);
        $memberId = $this->validarMembro($this->incomeMemberId);
        $conta = $this->incomeBankAccountId !== ''
            ? BankAccount::query()->findOrFail($this->incomeBankAccountId)
            : null;

        IncomeRecord::create([
            'member_id' => $memberId,
            'category_id' => $categoria->id,
            'description' => $data['incomeDescription'] !== '' ? $data['incomeDescription'] : null,
            'amount' => $data['incomeAmount'],
            'received_date' => CarbonImmutable::parse($data['incomeDate']),
            'bank_account_id' => $conta?->id,
            'is_recurring' => $this->incomeRecurring,
            'notes' => $this->incomeNotes !== '' ? $this->incomeNotes : null,
            'created_by_user_id' => auth()->id(),
            'is_private' => $memberId !== null && $this->incomeIsPrivate,
        ]);

        $conta?->applyToBalance($data['incomeAmount']);

        session()->flash('status', 'Receita adicionada.');
        $this->resetIncomeForm();
        $this->showIncomeForm = false;
    }

    private function updateIncome(): void
    {
        $data = $this->validate([
            'incomeDescription' => ['nullable', 'string', 'max:255'],
            'incomeAmount' => ['required', 'numeric', 'gt:0'],
            'incomeDate' => ['required', 'date'],
            'incomeCategoryId' => ['required'],
            'incomeMemberId' => ['nullable'],
            'incomeBankAccountId' => ['nullable'],
            'incomeNotes' => ['nullable', 'string'],
        ], attributes: [
            'incomeAmount' => 'valor',
            'incomeDate' => 'data',
            'incomeCategoryId' => 'categoria',
        ]);

        $receita = IncomeRecord::query()->findOrFail($this->editingIncomeId);
        $categoria = IncomeCategory::query()->findOrFail($data['incomeCategoryId']);
        $memberId = $this->validarMembro($this->incomeMemberId);

        DB::transaction(function () use ($receita, $data, $categoria, $memberId): void {
            // Mesma ordem do updateExpense: desfaz o crédito antigo antes
            // de aplicar o novo, senão a mesma conta recebe o delta errado.
            if ($receita->bank_account_id !== null) {
                BankAccount::withoutProfileScope()->find($receita->bank_account_id)?->applyToBalance('-'.$receita->amount);
            }

            $contaNova = $this->incomeBankAccountId !== ''
                ? BankAccount::query()->findOrFail($this->incomeBankAccountId)
                : null;

            $receita->update([
                'member_id' => $memberId,
                'category_id' => $categoria->id,
                'description' => $data['incomeDescription'] !== '' ? $data['incomeDescription'] : null,
                'amount' => $data['incomeAmount'],
                'received_date' => CarbonImmutable::parse($data['incomeDate']),
                'bank_account_id' => $contaNova?->id,
                'is_recurring' => $this->incomeRecurring,
                'notes' => $this->incomeNotes !== '' ? $this->incomeNotes : null,
                'is_private' => $memberId !== null && $this->incomeIsPrivate,
            ]);

            $contaNova?->applyToBalance($data['incomeAmount']);
        });

        session()->flash('status', 'Receita atualizada.');
        $this->resetIncomeForm();
        $this->showIncomeForm = false;
    }

    private function resetIncomeForm(): void
    {
        $this->reset(
            'incomeDescription', 'incomeAmount', 'incomeCategoryId', 'incomeMemberId', 'incomeIsPrivate',
            'incomeBankAccountId', 'incomeRecurring', 'incomeNotes', 'editingIncomeId',
        );
        $this->incomeDate = CarbonImmutable::now()->toDateString();
        $this->resetErrorBag();
    }

    /**
     * ProfileMember não é BelongsToProfile (ver comentário no model) — sem
     * essa checagem manual, um member_id de outro perfil passaria direto e
     * o lançamento nasceria atribuído a um membro que não é deste cliente.
     */
    private function validarMembro(string $memberId): ?string
    {
        if ($memberId === '') {
            return null;
        }

        $membro = ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('id', $memberId)
            ->first();

        return $membro?->id;
    }

    // -----------------------------------------------------------------
    // Dados
    // -----------------------------------------------------------------

    /** @return Collection<int, ExpenseRecord> */
    public function getExpensesProperty(): Collection
    {
        $query = ExpenseRecord::query()
            ->forPeriod($this->year, $this->month)
            ->with(['category', 'subcategory', 'member', 'creditCard', 'installmentGroup', 'invoice'])
            ->orderByDesc('expense_date');

        if ($this->necessity !== '') {
            $query->where('necessity', $this->necessity);
        }

        if ($this->categoryId !== '') {
            $query->where('category_id', $this->categoryId);
        }

        if ($this->memberId !== '') {
            $query->where('member_id', $this->memberId);
        }

        $this->applyPrivacyTabFilter($query);

        return $query->get();
    }

    /** @return Collection<int, IncomeRecord> */
    public function getIncomesProperty(): Collection
    {
        $query = IncomeRecord::query()
            ->forPeriod($this->year, $this->month)
            ->with(['category', 'member'])
            ->orderByDesc('received_date');

        if ($this->memberId !== '') {
            $query->where('member_id', $this->memberId);
        }

        $this->applyPrivacyTabFilter($query);

        return $query->get();
    }

    /**
     * Casal (member_id nulo) ou um membro específico — a aba de
     * privacidade, quando visível (ver HasPrivacyTabs). ExpenseRecord e
     * IncomeRecord têm member_id na própria linha, filtro direto.
     */
    private function applyPrivacyTabFilter(Builder $query): void
    {
        if (! $this->showPrivacyTabs) {
            return;
        }

        if ($this->viewAs === '') {
            $query->whereNull('member_id');
        } else {
            $query->where('member_id', $this->viewAs);
        }
    }

    public function getTotalIncomeProperty(): string
    {
        return Money::sum($this->incomes->pluck('amount'));
    }

    public function getTotalExpenseProperty(): string
    {
        return Money::sum($this->expenses->pluck('amount'));
    }

    /** Sobra do mês: o número que o cliente realmente quer ver. */
    public function getBalanceProperty(): string
    {
        return bcsub($this->totalIncome, $this->totalExpense, 2);
    }

    /** @return array<string, string> necessidade => total */
    public function getByNecessityProperty(): array
    {
        $totais = [];

        foreach (Necessity::cases() as $caso) {
            $totais[$caso->value] = Money::sum(
                $this->expenses->where('necessity', $caso)->pluck('amount')
            );
        }

        return $totais;
    }

    public function render()
    {
        $profileId = app(ProfileContext::class)->profileId();

        return view('livewire.cash-flow.cash-flow-index', [
            'showPrivacyTabs' => $this->showPrivacyTabs,
            'privacyMembers' => $this->privacyMembers,
            'expenses' => $this->expenses,
            'incomes' => $this->incomes,
            'totalIncome' => $this->totalIncome,
            'totalExpense' => $this->totalExpense,
            'balance' => $this->balance,
            'byNecessity' => $this->byNecessity,
            'categories' => ExpenseCategory::available()->get(),
            'incomeCategories' => IncomeCategory::available()->get(),
            'expenseSubcategories' => $this->expenseSubcategories,
            'bankAccounts' => BankAccount::query()->active()->orderBy('bank_name')->get(),
            'creditCards' => CreditCard::query()->active()->orderBy('card_name')->get(),
            'members' => ProfileMember::query()
                ->where('profile_id', $profileId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'maxInstallments' => config('cerne.installments.max'),
            'periodLabel' => $this->periodStart()->translatedFormat('F \d\e Y'),
        ]);
    }
}
