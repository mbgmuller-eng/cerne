<?php

namespace App\Livewire\FixedBills;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\Necessity;
use App\Enums\RecurrenceType;
use App\Enums\RecurringIncomeStatus;
use App\Livewire\Concerns\HasPrivacyTabs;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\BankAccount;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\FixedBill;
use App\Models\FixedBillPayment;
use App\Models\IncomeCategory;
use App\Models\ProfileMember;
use App\Models\RecurringIncome;
use App\Models\RecurringIncomeOccurrence;
use App\Services\FixedBillService;
use App\Services\RecurringIncomeService;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Contas fixas do mês — despesas e receitas recorrentes lado a lado.
 *
 * A tela é a face das rotinas agendadas (FixedBillService,
 * RecurringIncomeService): mostra o que a geração mensal criou e o que a
 * marcação de atraso já sinalizou. Recorrência é conceito diferente de
 * parcela de cartão (InstallmentService, na tela de Fluxo de Caixa) — uma
 * compra pode ter as duas coisas ao mesmo tempo, uma não substitui a
 * outra.
 */
#[Layout('components.layouts.app')]
class FixedBillsIndex extends Component
{
    use RequiresActiveProfile;
    use HasPrivacyTabs;

    protected function privacyModels(): array
    {
        return [FixedBill::class, RecurringIncome::class];
    }

    #[Url]
    public ?int $year = null;

    #[Url]
    public ?int $month = null;

    #[Url]
    public string $tab = 'despesas';

    /** Valor digitado para as contas/receitas de valor variável, indexado pelo id do vencimento. */
    public array $valorPago = [];

    // -----------------------------------------------------------------
    // Formulário — Conta fixa (despesa)
    // -----------------------------------------------------------------

    public bool $showBillForm = false;

    public string $billName = '';

    public string $billAmount = '';

    public string $billRecurrence = 'monthly';

    public string $billDueDay = '';

    public string $billDueWeekday = '';

    public string $billDueMonth = '';

    public string $billNecessity = '';

    public string $billCategoryId = '';

    public string $billSubcategoryId = '';

    public string $billNewSubcategory = '';

    public string $billMemberId = '';

    public bool $billIsPrivate = false;

    public string $billBankAccountId = '';

    public bool $billIsVariable = false;

    public string $billNotes = '';

    // -----------------------------------------------------------------
    // Formulário — Receita recorrente
    // -----------------------------------------------------------------

    public bool $showIncomeForm = false;

    public string $incomeName = '';

    public string $incomeAmount = '';

    public string $incomeRecurrence = 'monthly';

    public string $incomeDueDay = '';

    public string $incomeDueWeekday = '';

    public string $incomeDueMonth = '';

    public string $incomeCategoryId = '';

    public string $incomeMemberId = '';

    public bool $incomeIsPrivate = false;

    public string $incomeBankAccountId = '';

    public bool $incomeIsVariable = false;

    public string $incomeNotes = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();

        $hoje = CarbonImmutable::now();
        $this->year ??= $hoje->year;
        $this->month ??= $hoje->month;

        // Garante que o mês visto tem seus vencimentos — o usuário pode
        // navegar para um mês futuro antes de o cron chegar nele.
        app(FixedBillService::class)->generateForMonth($this->year, $this->month);
        app(RecurringIncomeService::class)->generateForMonth($this->year, $this->month);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['despesas', 'receitas'], true) ? $tab : 'despesas';
    }

    public function previousMonth(): void
    {
        $d = CarbonImmutable::create($this->year, $this->month, 1)->subMonth();
        $this->year = $d->year;
        $this->month = $d->month;
        app(FixedBillService::class)->generateForMonth($this->year, $this->month);
        app(RecurringIncomeService::class)->generateForMonth($this->year, $this->month);
    }

    public function nextMonth(): void
    {
        $d = CarbonImmutable::create($this->year, $this->month, 1)->addMonth();
        $this->year = $d->year;
        $this->month = $d->month;
        app(FixedBillService::class)->generateForMonth($this->year, $this->month);
        app(RecurringIncomeService::class)->generateForMonth($this->year, $this->month);
    }

    // -----------------------------------------------------------------
    // Despesa — pagar / pular / cadastrar
    // -----------------------------------------------------------------

    public function pay(string $paymentId, FixedBillService $service): void
    {
        $pagamento = FixedBillPayment::with('fixedBill')->findOrFail($paymentId);

        $valor = $pagamento->fixedBill->is_variable
            ? ($this->valorPago[$paymentId] ?? null)
            : null;

        if ($pagamento->fixedBill->is_variable && ! $valor) {
            $this->addError('valorPago.'.$paymentId, 'Informe o valor pago.');

            return;
        }

        $service->pay($pagamento, $valor ? Money::parse($valor) : null, null, auth()->id());

        unset($this->valorPago[$paymentId]);
        session()->flash('status', 'Pagamento registrado.');
    }

    public function skip(string $paymentId, FixedBillService $service): void
    {
        $service->skip(FixedBillPayment::findOrFail($paymentId), 'Pulada pelo usuário');

        session()->flash('status', 'Conta marcada como pulada.');
    }

    public function toggleBillForm(): void
    {
        $this->showBillForm = ! $this->showBillForm;
        $this->showIncomeForm = false;

        if ($this->showBillForm) {
            $this->resetBillForm();
        }
    }

    /**
     * Mesmo raciocínio de CashFlowIndex::updatedExpenseNecessity(): só
     * limpa a categoria se ela deixou de valer pra nova necessidade, e
     * Investimento nunca usa subcategoria.
     */
    public function updatedBillNecessity(): void
    {
        if ($this->billNecessity === Necessity::Investment->value) {
            $this->billSubcategoryId = '';
            $this->billNewSubcategory = '';
        }

        if ($this->billCategoryId === '' || $this->billFormCategories->contains('id', $this->billCategoryId)) {
            return;
        }

        $this->billCategoryId = '';
        $this->billSubcategoryId = '';
    }

    /** @return Collection<int, ExpenseCategory> */
    public function getBillFormCategoriesProperty(): Collection
    {
        return ExpenseCategory::available()
            ->when(
                $this->billNecessity === Necessity::Investment->value,
                fn (Builder $query) => $query->where('necessity', Necessity::Investment->value),
                fn (Builder $query) => $query->whereNull('necessity'),
            )
            ->get();
    }

    /** @return Collection<int, ExpenseSubcategory> */
    public function getBillSubcategoriesProperty(): Collection
    {
        if ($this->billCategoryId === '') {
            return collect();
        }

        return ExpenseSubcategory::query()
            ->where('category_id', $this->billCategoryId)
            ->available()
            ->get();
    }

    public function saveBill(FixedBillService $service): void
    {
        $data = $this->validate([
            'billName' => ['required', 'string', 'max:255'],
            'billAmount' => ['required', 'numeric', 'gt:0'],
            'billRecurrence' => ['required', Rule::enum(RecurrenceType::class)],
            'billDueDay' => ['required_if:billRecurrence,monthly,annual', 'nullable', 'integer', 'between:1,31'],
            'billDueWeekday' => ['required_if:billRecurrence,weekly', 'nullable', 'integer', 'between:0,6'],
            'billDueMonth' => ['required_if:billRecurrence,annual', 'nullable', 'integer', 'between:1,12'],
            'billNecessity' => ['required', Rule::enum(Necessity::class)],
            'billCategoryId' => ['required'],
            'billSubcategoryId' => ['nullable'],
            'billNewSubcategory' => ['nullable', 'string', 'max:255'],
            'billMemberId' => ['nullable'],
            'billBankAccountId' => ['nullable'],
            'billNotes' => ['nullable', 'string'],
        ], attributes: [
            'billName' => 'nome',
            'billAmount' => 'valor',
            'billRecurrence' => 'recorrência',
            'billDueDay' => 'dia do vencimento',
            'billDueWeekday' => 'dia da semana',
            'billDueMonth' => 'mês',
            'billNecessity' => 'necessidade',
            'billCategoryId' => 'categoria',
            'billSubcategoryId' => 'subcategoria',
        ]);

        $this->validarSubcategoriaObrigatoria();

        $categoria = ExpenseCategory::query()->findOrFail($data['billCategoryId']);
        $subcategoriaId = $this->resolveSubcategoryId($categoria);
        $memberId = $this->validarMembro($this->billMemberId);
        $conta = $this->billBankAccountId !== ''
            ? BankAccount::query()->findOrFail($this->billBankAccountId)
            : null;

        $service->create([
            'name' => $data['billName'],
            'amount' => $data['billAmount'],
            'recurrence' => RecurrenceType::from($data['billRecurrence']),
            'due_day' => $data['billDueDay'] !== null ? (int) $data['billDueDay'] : null,
            'due_weekday' => $data['billDueWeekday'] !== null ? (int) $data['billDueWeekday'] : null,
            'due_month' => $data['billDueMonth'] !== null ? (int) $data['billDueMonth'] : null,
            'necessity' => Necessity::from($data['billNecessity']),
            'category_id' => $categoria->id,
            'subcategory_id' => $subcategoriaId,
            'member_id' => $memberId,
            'bank_account_id' => $conta?->id,
            'is_variable' => $this->billIsVariable,
            'notes' => $this->billNotes !== '' ? $this->billNotes : null,
            'is_private' => $memberId !== null && $this->billIsPrivate,
        ]);

        session()->flash('status', 'Conta fixa cadastrada.');
        $this->resetBillForm();
        $this->showBillForm = false;
    }

    /**
     * Mesma checagem manual de CashFlowIndex::validarSubcategoriaObrigatoria()
     * — subcategoria vem de um select OU de um campo de texto livre, e
     * `Rule::requiredIf` não expressa "um destes dois" sozinho.
     */
    private function validarSubcategoriaObrigatoria(): void
    {
        if ($this->billNecessity === Necessity::Investment->value) {
            return;
        }

        if ($this->billSubcategoryId !== '' || trim($this->billNewSubcategory) !== '') {
            return;
        }

        throw ValidationException::withMessages([
            'billSubcategoryId' => 'Selecione uma subcategoria (ou crie uma nova).',
        ]);
    }

    private function resolveSubcategoryId(ExpenseCategory $categoria): ?string
    {
        if (trim($this->billNewSubcategory) !== '') {
            return ExpenseSubcategory::createCustom($categoria, $this->billNewSubcategory)->id;
        }

        if ($this->billSubcategoryId === '') {
            return null;
        }

        return ExpenseSubcategory::query()->findOrFail($this->billSubcategoryId)->id;
    }

    private function resetBillForm(): void
    {
        $this->reset(
            'billName', 'billAmount', 'billDueDay', 'billDueWeekday', 'billDueMonth', 'billNecessity',
            'billCategoryId', 'billSubcategoryId', 'billNewSubcategory',
            'billMemberId', 'billIsPrivate', 'billBankAccountId', 'billIsVariable', 'billNotes',
        );
        $this->billRecurrence = 'monthly';
        $this->resetErrorBag();
    }

    /** @return Collection<int, FixedBillPayment> */
    public function getPaymentsProperty(): Collection
    {
        $query = FixedBillPayment::query()
            ->forPeriod($this->year, $this->month)
            ->with('fixedBill.category', 'fixedBill.member');

        $this->applyPrivacyTabFilter($query, 'fixedBill');

        return $query->get()
            ->sortBy([
                fn ($a, $b) => $a->due_date <=> $b->due_date,
                fn ($a, $b) => $a->isPaid() <=> $b->isPaid(),
            ])
            ->values();
    }

    public function getTotalProperty(): string
    {
        return Money::sum($this->payments->map(fn (FixedBillPayment $p) => $p->effectiveAmount()));
    }

    public function getOutstandingProperty(): string
    {
        return Money::sum(
            $this->payments
                ->filter(fn (FixedBillPayment $p) => $p->status->isOutstanding())
                ->map(fn (FixedBillPayment $p) => $p->effectiveAmount())
        );
    }

    // -----------------------------------------------------------------
    // Receita — receber / pular / cadastrar
    // -----------------------------------------------------------------

    public function receive(string $occurrenceId, RecurringIncomeService $service): void
    {
        $ocorrencia = RecurringIncomeOccurrence::with('recurringIncome')->findOrFail($occurrenceId);

        $valor = $ocorrencia->recurringIncome->is_variable
            ? ($this->valorPago[$occurrenceId] ?? null)
            : null;

        if ($ocorrencia->recurringIncome->is_variable && ! $valor) {
            $this->addError('valorPago.'.$occurrenceId, 'Informe o valor recebido.');

            return;
        }

        $service->receive($ocorrencia, $valor ? Money::parse($valor) : null, null, auth()->id());

        unset($this->valorPago[$occurrenceId]);
        session()->flash('status', 'Recebimento registrado.');
    }

    public function skipIncome(string $occurrenceId, RecurringIncomeService $service): void
    {
        $service->skip(RecurringIncomeOccurrence::findOrFail($occurrenceId), 'Pulada pelo usuário');

        session()->flash('status', 'Receita marcada como pulada.');
    }

    public function toggleIncomeForm(): void
    {
        $this->showIncomeForm = ! $this->showIncomeForm;
        $this->showBillForm = false;

        if ($this->showIncomeForm) {
            $this->resetIncomeForm();
        }
    }

    public function saveIncome(RecurringIncomeService $service): void
    {
        $data = $this->validate([
            'incomeName' => ['required', 'string', 'max:255'],
            'incomeAmount' => ['required', 'numeric', 'gt:0'],
            'incomeRecurrence' => ['required', Rule::enum(RecurrenceType::class)],
            'incomeDueDay' => ['required_if:incomeRecurrence,monthly,annual', 'nullable', 'integer', 'between:1,31'],
            'incomeDueWeekday' => ['required_if:incomeRecurrence,weekly', 'nullable', 'integer', 'between:0,6'],
            'incomeDueMonth' => ['required_if:incomeRecurrence,annual', 'nullable', 'integer', 'between:1,12'],
            'incomeCategoryId' => ['required'],
            'incomeMemberId' => ['nullable'],
            'incomeBankAccountId' => ['nullable'],
            'incomeNotes' => ['nullable', 'string'],
        ], attributes: [
            'incomeName' => 'nome',
            'incomeAmount' => 'valor',
            'incomeRecurrence' => 'recorrência',
            'incomeDueDay' => 'dia do recebimento',
            'incomeDueWeekday' => 'dia da semana',
            'incomeDueMonth' => 'mês',
            'incomeCategoryId' => 'categoria',
        ]);

        $categoria = IncomeCategory::query()->findOrFail($data['incomeCategoryId']);
        $memberId = $this->validarMembro($this->incomeMemberId);
        $conta = $this->incomeBankAccountId !== ''
            ? BankAccount::query()->findOrFail($this->incomeBankAccountId)
            : null;

        $service->create([
            'name' => $data['incomeName'],
            'amount' => $data['incomeAmount'],
            'recurrence' => RecurrenceType::from($data['incomeRecurrence']),
            'due_day' => $data['incomeDueDay'] !== null ? (int) $data['incomeDueDay'] : null,
            'due_weekday' => $data['incomeDueWeekday'] !== null ? (int) $data['incomeDueWeekday'] : null,
            'due_month' => $data['incomeDueMonth'] !== null ? (int) $data['incomeDueMonth'] : null,
            'category_id' => $categoria->id,
            'member_id' => $memberId,
            'bank_account_id' => $conta?->id,
            'is_variable' => $this->incomeIsVariable,
            'notes' => $this->incomeNotes !== '' ? $this->incomeNotes : null,
            'is_private' => $memberId !== null && $this->incomeIsPrivate,
        ]);

        session()->flash('status', 'Receita recorrente cadastrada.');
        $this->resetIncomeForm();
        $this->showIncomeForm = false;
    }

    private function resetIncomeForm(): void
    {
        $this->reset(
            'incomeName', 'incomeAmount', 'incomeDueDay', 'incomeDueWeekday', 'incomeDueMonth',
            'incomeCategoryId', 'incomeMemberId', 'incomeIsPrivate', 'incomeBankAccountId',
            'incomeIsVariable', 'incomeNotes',
        );
        $this->incomeRecurrence = 'monthly';
        $this->resetErrorBag();
    }

    /** @return Collection<int, RecurringIncomeOccurrence> */
    public function getIncomeOccurrencesProperty(): Collection
    {
        $query = RecurringIncomeOccurrence::query()
            ->forPeriod($this->year, $this->month)
            ->with('recurringIncome.category', 'recurringIncome.member');

        $this->applyPrivacyTabFilter($query, 'recurringIncome');

        return $query->get()
            ->sortBy([
                fn ($a, $b) => $a->due_date <=> $b->due_date,
                fn ($a, $b) => $a->isReceived() <=> $b->isReceived(),
            ])
            ->values();
    }

    /**
     * Casal (member_id nulo) ou um membro específico — a aba de
     * privacidade, quando visível (ver HasPrivacyTabs). FixedBillPayment
     * e RecurringIncomeOccurrence não têm member_id na própria linha —
     * quem tem é a conta fixa/receita recorrente relacionada.
     */
    private function applyPrivacyTabFilter(Builder $query, string $relacao): void
    {
        if (! $this->showPrivacyTabs) {
            return;
        }

        if ($this->viewAs === '') {
            $query->whereHas($relacao, fn ($q) => $q->whereNull('member_id'));
        } else {
            $query->whereHas($relacao, fn ($q) => $q->where('member_id', $this->viewAs));
        }
    }

    public function getIncomeTotalProperty(): string
    {
        return Money::sum($this->incomeOccurrences->map(fn (RecurringIncomeOccurrence $o) => $o->effectiveAmount()));
    }

    public function getIncomeOutstandingProperty(): string
    {
        return Money::sum(
            $this->incomeOccurrences
                ->filter(fn (RecurringIncomeOccurrence $o) => $o->status->isOutstanding())
                ->map(fn (RecurringIncomeOccurrence $o) => $o->effectiveAmount())
        );
    }

    // -----------------------------------------------------------------
    // Comum
    // -----------------------------------------------------------------

    /**
     * ProfileMember não é BelongsToProfile — sem essa checagem manual, um
     * member_id de outro perfil passaria direto (mesmo raciocínio de
     * CashFlowIndex::validarMembro).
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

    public function render()
    {
        $profileId = app(ProfileContext::class)->profileId();

        return view('livewire.fixed-bills.fixed-bills-index', [
            'showPrivacyTabs' => $this->showPrivacyTabs,
            'privacyMembers' => $this->privacyMembers,
            'payments' => $this->payments,
            'total' => $this->total,
            'outstanding' => $this->outstanding,
            'incomeOccurrences' => $this->incomeOccurrences,
            'incomeTotal' => $this->incomeTotal,
            'incomeOutstanding' => $this->incomeOutstanding,
            'periodLabel' => CarbonImmutable::create($this->year, $this->month, 1)->translatedFormat('F \d\e Y'),
            'hasBills' => FixedBill::query()->active()->exists(),
            'hasIncomes' => RecurringIncome::query()->active()->exists(),
            'billFormCategories' => $this->billFormCategories,
            'billSubcategories' => $this->billSubcategories,
            'incomeCategories' => IncomeCategory::available()->get(),
            'bankAccounts' => BankAccount::query()->active()->orderBy('bank_name')->get(),
            'members' => ProfileMember::query()
                ->where('profile_id', $profileId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
