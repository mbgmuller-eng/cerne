<?php

namespace App\Livewire\FixedBills;

use App\Enums\FixedBillPaymentStatus;
use App\Enums\Necessity;
use App\Enums\RecurrenceType;
use App\Enums\RecurringIncomeStatus;
use App\Livewire\Concerns\HasPrivacyTabs;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\BankAccount;
use App\Models\CreditCard;
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
use App\Support\Forms\FormConsequence;
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

    /** Nulo = criando; preenchido = editando esta conta fixa. */
    public ?string $editingBillId = null;

    public ?string $confirmingDeleteBillId = null;

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

    /** 'conta' (debita de uma conta bancária) ou 'cartao' (vai para a fatura do cartão no vencimento). */
    public string $billPaymentMethod = 'conta';

    public string $billCreditCardId = '';

    public bool $billIsVariable = false;

    public string $billNotes = '';

    // -----------------------------------------------------------------
    // Formulário — Receita recorrente
    // -----------------------------------------------------------------

    public bool $showIncomeForm = false;

    /** Nulo = criando; preenchido = editando esta receita recorrente. */
    public ?string $editingIncomeId = null;

    public ?string $confirmingDeleteIncomeId = null;

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
        // Corretor só entra em Seguros — nenhuma outra tela financeira.
        abort_if(auth()->user()?->isBroker(), 403);

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

    public function editBill(string $id): void
    {
        $conta = FixedBill::query()->findOrFail($id);

        $this->editingBillId = $conta->id;
        $this->billName = $conta->name;
        $this->billAmount = $conta->amount;
        $this->billRecurrence = $conta->recurrence->value;
        // Só o que a recorrência usa: um 0 antigo em campo que a conta não usa (dia da semana de conta mensal, mês de conta que não é anual) voltava
        // para o formulário, e a validação recusava um campo escondido, sem mensagem nenhuma na tela.
        $this->billDueDay = $conta->due_day ? (string) $conta->due_day : '';
        $this->billDueWeekday = $conta->recurrence === RecurrenceType::Weekly && $conta->due_weekday !== null ? (string) $conta->due_weekday : '';
        $this->billDueMonth = $conta->recurrence === RecurrenceType::Annual && $conta->due_month ? (string) $conta->due_month : '';
        $this->billNecessity = $conta->necessity?->value ?? '';
        $this->billCategoryId = $conta->category_id ?? '';
        $this->billSubcategoryId = $conta->subcategory_id ?? '';
        $this->billMemberId = $conta->member_id ?? '';
        $this->billIsPrivate = $conta->is_private;
        $this->billBankAccountId = $conta->bank_account_id ?? '';
        $this->billCreditCardId = $conta->credit_card_id ?? '';
        $this->billPaymentMethod = $conta->credit_card_id !== null ? 'cartao' : 'conta';
        $this->billIsVariable = $conta->is_variable;
        $this->billNotes = $conta->notes ?? '';
        $this->showBillForm = true;
        $this->showIncomeForm = false;
    }

    public function confirmDeleteBill(string $id): void
    {
        $this->confirmingDeleteBillId = $id;
    }

    public function cancelDeleteBill(): void
    {
        $this->confirmingDeleteBillId = null;
    }

    public function deleteBill(string $id, FixedBillService $service): void
    {
        $service->deactivate(FixedBill::query()->findOrFail($id));

        if ($this->editingBillId === $id) {
            $this->showBillForm = false;
            $this->resetBillForm();
        }

        $this->confirmingDeleteBillId = null;
        session()->flash('status', 'Conta fixa excluída.');
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
            'billPaymentMethod' => ['required', Rule::in(['conta', 'cartao'])],
            'billCreditCardId' => ['required_if:billPaymentMethod,cartao', 'nullable'],
            'billNotes' => ['nullable', 'string'],
        ], messages: [
            'billCreditCardId.required_if' => 'Escolha o cartão.',
            // O required_if cru diria "quando recorrência for monthly": o valor do enum sai em inglês.
            'billDueDay.required_if' => 'Informe o dia do vencimento.',
            'billDueWeekday.required_if' => 'Escolha o dia da semana.',
            'billDueMonth.required_if' => 'Escolha o mês do vencimento.',
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
        // Conta bancária e cartão são excludentes: a conta fixa vai para UM dos dois. O id vem do navegador, então passa por findOrFail sob escopo.
        $noCartao = $this->billPaymentMethod === 'cartao';
        $cartao = $noCartao ? CreditCard::query()->where('is_active', true)->findOrFail($this->billCreditCardId) : null;
        $conta = ! $noCartao && $this->billBankAccountId !== ''
            ? BankAccount::query()->findOrFail($this->billBankAccountId)
            : null;

        $dados = [
            'name' => $data['billName'],
            'amount' => $data['billAmount'],
            'recurrence' => RecurrenceType::from($data['billRecurrence']),
            ...$this->dueFields($data['billRecurrence'], $data['billDueDay'], $data['billDueWeekday'], $data['billDueMonth']),
            'necessity' => Necessity::from($data['billNecessity']),
            'category_id' => $categoria->id,
            'subcategory_id' => $subcategoriaId,
            'member_id' => $memberId,
            'bank_account_id' => $conta?->id,
            'credit_card_id' => $cartao?->id,
            'is_variable' => $this->billIsVariable,
            'notes' => $this->billNotes !== '' ? $this->billNotes : null,
            'is_private' => $memberId !== null && $this->billIsPrivate,
        ];

        if ($this->editingBillId !== null) {
            $service->update(FixedBill::query()->findOrFail($this->editingBillId), $dados);
            session()->flash('status', 'Conta fixa atualizada.');
        } else {
            $service->create($dados);
            session()->flash('status', 'Conta fixa cadastrada.');
        }

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

    /**
     * Dia, dia da semana e mês de um vencimento: SÓ o que a recorrência usa (mensal usa o dia; semanal, o dia da semana; anual, o dia e o mês), o resto
     * vai NULO. O campo vazio do formulário é uma string vazia e `(int) ''` é 0: gravar assim deixava 0 em mês e dia da semana de conta mensal, e esse 0
     * voltava ao abrir a edição.
     *
     * @return array{due_day: ?int, due_weekday: ?int, due_month: ?int}
     */
    private function dueFields(string $recorrencia, ?string $dia, ?string $semana, ?string $mes): array
    {
        $tipo = RecurrenceType::from($recorrencia);
        $inteiro = fn (?string $v): ?int => $v !== null && $v !== '' && ctype_digit($v) ? (int) $v : null;

        return [
            'due_day' => in_array($tipo, [RecurrenceType::Monthly, RecurrenceType::Annual], true) ? $inteiro($dia) : null,
            'due_weekday' => $tipo === RecurrenceType::Weekly ? $inteiro($semana) : null,
            'due_month' => $tipo === RecurrenceType::Annual ? $inteiro($mes) : null,
        ];
    }

    private function resetBillForm(): void
    {
        $this->reset(
            'billName', 'billAmount', 'billDueDay', 'billDueWeekday', 'billDueMonth', 'billNecessity',
            'billCategoryId', 'billSubcategoryId', 'billNewSubcategory',
            'billMemberId', 'billIsPrivate', 'billBankAccountId', 'billCreditCardId', 'billIsVariable', 'billNotes', 'editingBillId',
        );
        $this->billRecurrence = 'monthly';
        $this->billPaymentMethod = 'conta';
        $this->resetErrorBag();
    }

    /** @return Collection<int, FixedBillPayment> */
    public function getPaymentsProperty(): Collection
    {
        $query = FixedBillPayment::query()
            ->forPeriod($this->year, $this->month)
            ->with('fixedBill.category', 'fixedBill.member', 'fixedBill.creditCard');

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

    public function editIncome(string $id): void
    {
        $receita = RecurringIncome::query()->findOrFail($id);

        $this->editingIncomeId = $receita->id;
        $this->incomeName = $receita->name;
        $this->incomeAmount = $receita->amount;
        $this->incomeRecurrence = $receita->recurrence->value;
        $this->incomeDueDay = $receita->due_day ? (string) $receita->due_day : '';
        $this->incomeDueWeekday = $receita->recurrence === RecurrenceType::Weekly && $receita->due_weekday !== null ? (string) $receita->due_weekday : '';
        $this->incomeDueMonth = $receita->recurrence === RecurrenceType::Annual && $receita->due_month ? (string) $receita->due_month : '';
        $this->incomeCategoryId = $receita->category_id ?? '';
        $this->incomeMemberId = $receita->member_id ?? '';
        $this->incomeIsPrivate = $receita->is_private;
        $this->incomeBankAccountId = $receita->bank_account_id ?? '';
        $this->incomeIsVariable = $receita->is_variable;
        $this->incomeNotes = $receita->notes ?? '';
        $this->showIncomeForm = true;
        $this->showBillForm = false;
    }

    public function confirmDeleteIncome(string $id): void
    {
        $this->confirmingDeleteIncomeId = $id;
    }

    public function cancelDeleteIncome(): void
    {
        $this->confirmingDeleteIncomeId = null;
    }

    public function deleteIncome(string $id, RecurringIncomeService $service): void
    {
        $service->deactivate(RecurringIncome::query()->findOrFail($id));

        if ($this->editingIncomeId === $id) {
            $this->showIncomeForm = false;
            $this->resetIncomeForm();
        }

        $this->confirmingDeleteIncomeId = null;
        session()->flash('status', 'Receita recorrente excluída.');
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
        ], messages: [
            'incomeDueDay.required_if' => 'Informe o dia do recebimento.',
            'incomeDueWeekday.required_if' => 'Escolha o dia da semana.',
            'incomeDueMonth.required_if' => 'Escolha o mês do recebimento.',
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

        $dados = [
            'name' => $data['incomeName'],
            'amount' => $data['incomeAmount'],
            'recurrence' => RecurrenceType::from($data['incomeRecurrence']),
            ...$this->dueFields($data['incomeRecurrence'], $data['incomeDueDay'], $data['incomeDueWeekday'], $data['incomeDueMonth']),
            'category_id' => $categoria->id,
            'member_id' => $memberId,
            'bank_account_id' => $conta?->id,
            'is_variable' => $this->incomeIsVariable,
            'notes' => $this->incomeNotes !== '' ? $this->incomeNotes : null,
            'is_private' => $memberId !== null && $this->incomeIsPrivate,
        ];

        if ($this->editingIncomeId !== null) {
            $service->update(RecurringIncome::query()->findOrFail($this->editingIncomeId), $dados);
            session()->flash('status', 'Receita recorrente atualizada.');
        } else {
            $service->create($dados);
            session()->flash('status', 'Receita recorrente cadastrada.');
        }

        $this->resetIncomeForm();
        $this->showIncomeForm = false;
    }

    private function resetIncomeForm(): void
    {
        $this->reset(
            'incomeName', 'incomeAmount', 'incomeDueDay', 'incomeDueWeekday', 'incomeDueMonth',
            'incomeCategoryId', 'incomeMemberId', 'incomeIsPrivate', 'incomeBankAccountId',
            'incomeIsVariable', 'incomeNotes', 'editingIncomeId',
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

    /**
     * Os vencimentos (ou recebimentos) do MÊS CORRENTE que o cadastro cria na hora, com a mesma regra de recorrência que grava de verdade
     * (FixedBill::occurrencesInMonth). Vazio enquanto a recorrência não tem os campos que ela pede.
     *
     * @return list<CarbonImmutable>
     */
    private function datasDoMes(FixedBill|RecurringIncome $modelo, string $recorrencia, string $dia, string $semana, string $mes): array
    {
        $tipo = RecurrenceType::tryFrom($recorrencia);
        $diaValido = ctype_digit($dia) && (int) $dia >= 1 && (int) $dia <= 31;

        $completo = match ($tipo) {
            RecurrenceType::Monthly => $diaValido,
            RecurrenceType::Annual => $diaValido && ctype_digit($mes) && (int) $mes >= 1 && (int) $mes <= 12,
            RecurrenceType::Weekly => ctype_digit($semana) && (int) $semana <= 6,
            default => false,
        };

        if (! $completo) {
            return [];
        }

        $hoje = CarbonImmutable::now();
        $modelo->forceFill([
            'recurrence' => $tipo,
            'due_day' => $diaValido ? (int) $dia : null,
            'due_weekday' => ctype_digit($semana) ? (int) $semana : null,
            'due_month' => ctype_digit($mes) ? (int) $mes : null,
        ]);

        return $modelo->occurrencesInMonth($hoje->year, $hoje->month);
    }

    /**
     * "O que vai acontecer ao salvar" da conta fixa (ver FormConsequence).
     *
     * @param  Collection<int, BankAccount>  $contas
     * @return list<string>
     */
    private function billConsequence(Collection $contas, Collection $cartoes): array
    {
        if (! $this->showBillForm) {
            return [];
        }

        if ($this->editingBillId !== null) {
            $linhas = ['Valor, categoria e conta (ou cartão) valem a partir do próximo pagamento. O dia do vencimento vale só para meses ainda não gerados.'];

            $antes = FixedBill::query()->whereKey($this->editingBillId)->first(['credit_card_id', 'bank_account_id']);
            $passouAoCartao = $this->billPaymentMethod === 'cartao' && $this->billCreditCardId !== '' && $antes?->credit_card_id !== $this->billCreditCardId;

            if ($passouAoCartao) {
                $linhas[] = 'Conta no cartão: o que já venceu e está em aberto neste mês entra na fatura ao salvar, e os próximos vencimentos entram sozinhos. O que já foi pago ou debitado fica como está.';
            } elseif ($this->billPaymentMethod === 'conta' && $antes?->credit_card_id !== null) {
                $linhas[] = 'Os próximos vencimentos deixam de ir para a fatura do cartão. O que já foi lançado na fatura fica como está.';
            }

            // A marca de oculto vale na hora para os vencimentos (eles seguem a conta fixa), mas as despesas que um pagamento já criou não mudam.
            if ($this->billIsPrivate !== (bool) FixedBill::query()->whereKey($this->editingBillId)->value('is_private')) {
                $linhas[] = 'Ocultar ou mostrar vale já para os vencimentos. As despesas que pagamentos anteriores criaram no fluxo de caixa ficam como estão.';
            }

            return $linhas;
        }

        $linhas = [];
        $criacao = FormConsequence::recurringCreation($this->datasDoMes(new FixedBill, $this->billRecurrence, $this->billDueDay, $this->billDueWeekday, $this->billDueMonth));

        if ($criacao !== null) {
            $linhas[] = $criacao;
        }

        $categoria = $this->billFormCategories->firstWhere('id', $this->billCategoryId)?->name ?? 'a categoria escolhida';

        if ($this->billPaymentMethod === 'cartao') {
            $cartao = $cartoes->firstWhere('id', $this->billCreditCardId);
            $datas = $this->datasDoMes(new FixedBill, $this->billRecurrence, $this->billDueDay, $this->billDueWeekday, $this->billDueMonth);

            return [...$linhas, ...FormConsequence::payBillOnCard($cartao, $this->billAmount, $this->billIsVariable, $categoria, $datas[0] ?? null)];
        }

        $conta = $contas->firstWhere('id', $this->billBankAccountId);
        $linhas[] = FormConsequence::payBill($conta?->displayName(), $this->billAmount, $this->billIsVariable, $categoria);

        return $linhas;
    }

    /**
     * "O que vai acontecer ao salvar" da receita recorrente.
     *
     * @param  Collection<int, BankAccount>  $contas
     * @return list<string>
     */
    private function incomeConsequence(Collection $contas): array
    {
        if (! $this->showIncomeForm) {
            return [];
        }

        if ($this->editingIncomeId !== null) {
            $linhas = ['Valor, categoria e conta valem a partir do próximo recebimento. O dia do recebimento vale só para meses ainda não gerados.'];

            if ($this->incomeIsPrivate !== (bool) RecurringIncome::query()->whereKey($this->editingIncomeId)->value('is_private')) {
                $linhas[] = 'Ocultar ou mostrar vale já para os recebimentos previstos. As receitas que recebimentos anteriores criaram no fluxo de caixa ficam como estão.';
            }

            return $linhas;
        }

        $linhas = [];
        $criacao = FormConsequence::recurringCreation(
            $this->datasDoMes(new RecurringIncome, $this->incomeRecurrence, $this->incomeDueDay, $this->incomeDueWeekday, $this->incomeDueMonth),
            'recebimento',
        );

        if ($criacao !== null) {
            $linhas[] = $criacao;
        }

        $conta = $contas->firstWhere('id', $this->incomeBankAccountId);
        $categoria = IncomeCategory::available()->get()->firstWhere('id', $this->incomeCategoryId)?->name ?? 'a categoria escolhida';
        $linhas[] = FormConsequence::receiveIncome($conta?->displayName(), $this->incomeAmount, $this->incomeIsVariable, $categoria);

        return $linhas;
    }

    public function render()
    {
        $profileId = app(ProfileContext::class)->profileId();
        $bankAccounts = BankAccount::query()->active()->orderBy('bank_name')->get();
        $creditCards = CreditCard::query()->where('is_active', true)->orderBy('card_name')->get();

        return view('livewire.fixed-bills.fixed-bills-index', [
            'creditCards' => $creditCards,
            'billConsequence' => $this->billConsequence($bankAccounts, $creditCards),
            'incomeConsequence' => $this->incomeConsequence($bankAccounts),
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
            'bankAccounts' => $bankAccounts,
            'members' => ProfileMember::query()
                ->where('profile_id', $profileId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
