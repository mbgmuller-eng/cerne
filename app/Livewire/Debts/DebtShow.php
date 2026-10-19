<?php

namespace App\Livewire\Debts;

use App\Enums\DebtAmortization;
use App\Enums\ContemplationMode;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtRateBasis;
use App\Enums\DebtStatus;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\Goal;
use App\Models\ProfileMember;
use App\Services\DebtService;
use App\Support\Debts\DebtClassification;
use App\Support\Debts\DebtScheduleCalculator;
use App\Support\Debts\DebtSimulator;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Um contrato de dívida: saldo, cronograma e, em cada parcela, o pagamento ligado a uma despesa do fluxo de caixa.
 * Pagar cria a despesa; "vincular despesa" liga uma que já existe; desfazer solta a ligação sem apagar a despesa.
 */
#[Layout('components.layouts.app')]
class DebtShow extends Component
{
    public Debt $debt;

    // Pagar
    public bool $showPay = false;

    public ?string $payId = null;

    public string $payDate = '';

    public string $payAmount = '';

    public string $payAccountId = '';

    // Vincular a uma despesa existente
    public bool $showLink = false;

    public ?string $linkId = null;

    public string $linkExpenseId = '';

    public bool $linkReclassify = true;

    // Ajustar uma parcela em aberto
    public bool $showInstallment = false;

    public ?string $installmentId = null;

    public string $installmentAmount = '';

    public string $installmentDate = '';

    // Editar o contrato
    public bool $showEdit = false;

    public string $editName = '';

    public string $editCreditor = '';

    public string $editCreditorDocument = '';

    public string $editContractedOn = '';

    public string $editNecessity = 'essential';

    public string $editCategoryId = '';

    public string $editSubcategoryId = '';

    public string $editAccountId = '';

    public string $editMemberId = '';

    public bool $editPrivate = false;

    public string $editNotes = '';

    public bool $editReclassify = false;

    // Reajuste da carta (consórcio)
    public bool $showAdjustment = false;

    public string $adjustmentPercent = '';

    public string $adjustmentDate = '';

    // Contemplação (consórcio)
    public bool $showContemplation = false;

    public string $contemplatedOn = '';

    public string $contemplationMode = 'draw';

    public string $bidAmount = '';

    public string $creditReleased = '';

    public string $newInstallmentAmount = '';

    public string $remainingInstallments = '';

    public string $contemplationCategoryId = '';

    public string $contemplationSubcategoryId = '';

    public bool $markGoalAchieved = true;

    // Dívida em aberto: atualizar saldo, registrar acordo
    public bool $showBalance = false;

    public string $balanceAmount = '';

    public string $balanceDate = '';

    public string $balanceStatus = 'open';

    public bool $showAgreement = false;

    public string $agreementTotal = '';

    public string $agreementInstallments = '1';

    public string $agreementFirstDue = '';

    public string $agreementCategoryId = '';

    public string $agreementSubcategoryId = '';

    public string $agreementAccountId = '';

    // Simulador: antecipar parte do saldo ou fazer a portabilidade para outra taxa
    public bool $showSim = false;

    public string $simTab = 'prepay';

    public string $simExtra = '';

    public string $simMode = 'term';

    public string $simRate = '';

    public string $simRateBasis = 'annual_effective';

    public string $simTerm = '';

    public string $simFees = '';

    public string $simCost = '';

    /** @var array<string, mixed> */
    public array $simResult = [];

    public function mount(Debt $debt): void
    {
        abort_if(auth()->user()?->isBroker(), 403);

        $this->debt = $debt;
    }

    // ------------------------------------------------------------------ pagar

    public function openPay(string $id): void
    {
        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id);

        $this->resetValidation();
        $this->payId = $id;
        $this->payDate = now()->toDateString();
        $this->payAmount = (string) $parcela->amount;
        $this->payAccountId = (string) $this->debt->bank_account_id;
        $this->showPay = true;
    }

    public function pay(): void
    {
        $dados = $this->validate([
            'payDate' => ['required', 'date_format:Y-m-d'],
            'payAmount' => ['required', 'string', 'max:20'],
            'payAccountId' => ['nullable', 'string'],
        ], [], ['payDate' => 'data do pagamento', 'payAmount' => 'valor pago']);

        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($this->payId);

        try {
            app(DebtService::class)->pay($parcela, CarbonImmutable::createFromFormat('Y-m-d', $dados['payDate'])->startOfDay(), $dados['payAmount'], $dados['payAccountId'] ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError('payAmount', $e->getMessage());

            return;
        }

        $this->showPay = false;
        $this->debt->refresh();
    }

    // ------------------------------------------------------------------ simulador

    /** Só financiamento e empréstimo com taxa cadastrada e parcelas em aberto: parcelas informadas não têm juros para simular. */
    private function podeSimular(): bool
    {
        return in_array($this->debt->kind, [DebtKind::Financing, DebtKind::Loan], true)
            && $this->debt->amortization !== DebtAmortization::Fixed
            && $this->debt->rate !== null && $this->debt->rate_basis !== null
            && $this->debt->status === DebtStatus::Active
            && $this->debt->installments()->where('status', DebtInstallmentStatus::Open)->exists();
    }

    public function openSim(): void
    {
        abort_unless($this->podeSimular(), 404);

        $this->resetValidation();
        $this->simResult = [];
        $this->simTab = 'prepay';
        $this->simExtra = '';
        $this->simMode = 'term';
        $this->simRate = '';
        $this->simRateBasis = $this->debt->rate_basis->value;
        $this->simTerm = (string) $this->debt->installments()->where('status', DebtInstallmentStatus::Open)->count();
        $this->simFees = (string) ($this->debt->monthly_fees ?? '0.00');
        $this->simCost = '';
        $this->showSim = true;
    }

    public function updatedSimTab(): void
    {
        $this->simResult = [];
        $this->resetValidation();
    }

    public function simular(): void
    {
        abort_unless($this->podeSimular(), 404);

        $abertas = $this->debt->installments()->where('status', DebtInstallmentStatus::Open);
        $primeira = (clone $abertas)->first();
        $totais = (clone $abertas)->selectRaw('COUNT(*) as n, SUM(amount) as total, SUM(interest_amount) as juros')->first();
        $saldo = $this->debt->balance();
        $taxaAtual = DebtScheduleCalculator::monthlyRate((string) $this->debt->rate, $this->debt->rate_basis);
        $taxasFixas = Money::parse($this->debt->monthly_fees ?? 0);
        $restantes = (int) $totais->n;
        $totalAtual = Money::parse($totais->total);
        $jurosAtuais = Money::parse($totais->juros);

        try {
            if ($this->simTab === 'prepay') {
                $this->validate(['simExtra' => ['required', 'string', 'max:20'], 'simMode' => ['required', 'in:term,installment']], [], ['simExtra' => 'valor a antecipar']);
                $extra = Money::parse($this->simExtra);

                $r = DebtSimulator::prepay($this->debt->amortization, $saldo, $taxaAtual, $taxasFixas, $restantes, (string) $primeira->principal_amount, (string) $primeira->amount, $extra, $this->simMode);
                $this->simResult = $r + [
                    'tab' => 'prepay', 'extra' => $extra, 'old_term' => $restantes, 'old_installment' => (string) $primeira->amount,
                    'old_total' => $totalAtual, 'old_interest' => $jurosAtuais,
                    'saving' => bcsub($totalAtual, bcadd($extra, $r['total_to_pay'], 2), 2),
                ];

                return;
            }

            $this->validate([
                'simRate' => ['required', 'string', 'max:12'], 'simRateBasis' => ['required', 'in:'.implode(',', array_keys(DebtRateBasis::options()))],
                'simTerm' => ['required', 'integer', 'min:1', 'max:480'], 'simFees' => ['nullable', 'string', 'max:20'], 'simCost' => ['nullable', 'string', 'max:20'],
            ], [], ['simRate' => 'taxa nova', 'simTerm' => 'prazo novo', 'simFees' => 'taxas por parcela', 'simCost' => 'custo da operação']);

            $novaTaxa = DebtScheduleCalculator::monthlyRate(str_replace(',', '.', trim($this->simRate)), DebtRateBasis::from($this->simRateBasis));
            $r = DebtSimulator::port($this->debt->amortization, $saldo, $novaTaxa, (int) $this->simTerm, Money::parse($this->simFees ?: 0), Money::parse($this->simCost ?: 0));
            $this->simResult = $r + [
                'tab' => 'port', 'old_term' => $restantes, 'old_installment' => (string) $primeira->amount, 'old_total' => $totalAtual,
                'old_interest' => $jurosAtuais, 'saving' => bcsub($totalAtual, $r['total_to_pay'], 2), 'balance' => $saldo,
            ];
        } catch (InvalidArgumentException $e) {
            $this->simResult = [];
            $this->addError($this->simTab === 'prepay' ? 'simExtra' : 'simRate', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ parcela pulada

    public function skip(string $id): void
    {
        try {
            app(DebtService::class)->markDiluted(DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id));
        } catch (InvalidArgumentException $e) {
            $this->addError('skip', $e->getMessage());
        }

        $this->debt->refresh();
    }

    public function reopen(string $id): void
    {
        try {
            app(DebtService::class)->reopenDiluted(DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id));
        } catch (InvalidArgumentException $e) {
            $this->addError('skip', $e->getMessage());
        }

        $this->debt->refresh();
    }

    // ------------------------------------------------------------------ vincular

    public function openLink(string $id): void
    {
        DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id);

        $this->resetValidation();
        $this->linkId = $id;
        $this->linkExpenseId = '';
        $this->linkReclassify = true;
        $this->showLink = true;
    }

    public function linkExpense(): void
    {
        $this->validate(['linkExpenseId' => ['required', 'string']], [], ['linkExpenseId' => 'despesa']);

        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($this->linkId);
        $despesa = ExpenseRecord::query()->findOrFail($this->linkExpenseId);

        try {
            app(DebtService::class)->linkExpense($parcela, $despesa, $this->linkReclassify);
        } catch (InvalidArgumentException $e) {
            $this->addError('linkExpenseId', $e->getMessage());

            return;
        }

        $this->showLink = false;
        $this->debt->refresh();
    }

    public function unlink(string $id): void
    {
        app(DebtService::class)->unlink(DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id));

        $this->debt->refresh();
    }

    // ------------------------------------------------------------------ ajustar parcela

    public function openInstallment(string $id): void
    {
        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($id);

        $this->resetValidation();
        $this->installmentId = $id;
        $this->installmentAmount = (string) $parcela->amount;
        $this->installmentDate = $parcela->due_date->toDateString();
        $this->showInstallment = true;
    }

    public function saveInstallment(): void
    {
        $dados = $this->validate([
            'installmentAmount' => ['required', 'string', 'max:20'],
            'installmentDate' => ['required', 'date_format:Y-m-d'],
        ], [], ['installmentAmount' => 'valor', 'installmentDate' => 'vencimento']);

        try {
            app(DebtService::class)->updateInstallment(
                DebtInstallment::query()->where('debt_id', $this->debt->id)->findOrFail($this->installmentId),
                $dados['installmentAmount'],
                CarbonImmutable::createFromFormat('Y-m-d', $dados['installmentDate']),
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('installmentAmount', $e->getMessage());

            return;
        }

        $this->showInstallment = false;
    }

    // ------------------------------------------------------------------ reajuste da carta

    public function openAdjustment(): void
    {
        $this->resetValidation();
        $this->adjustmentPercent = '';
        $this->adjustmentDate = ($this->debt->next_adjustment_on ?? now())->toDateString();
        $this->showAdjustment = true;
    }

    public function saveAdjustment(): void
    {
        $dados = $this->validate([
            'adjustmentPercent' => ['required', 'string', 'max:12'],
            'adjustmentDate' => ['required', 'date_format:Y-m-d'],
        ], [], ['adjustmentPercent' => 'percentual', 'adjustmentDate' => 'data do reajuste']);

        try {
            app(DebtService::class)->registerAdjustment($this->debt, $dados['adjustmentPercent'], CarbonImmutable::createFromFormat('Y-m-d', $dados['adjustmentDate'])->startOfDay());
        } catch (InvalidArgumentException $e) {
            $this->addError('adjustmentPercent', $e->getMessage());

            return;
        }

        $this->showAdjustment = false;
        $this->debt->refresh();
    }

    // ------------------------------------------------------------------ contemplação

    public function openContemplation(): void
    {
        $sugestao = DebtClassification::consortiumContemplated($this->debt->consortium_type);

        $this->resetValidation();
        $this->contemplatedOn = now()->toDateString();
        $this->contemplationMode = ContemplationMode::Draw->value;
        $this->bidAmount = '';
        $this->creditReleased = (string) $this->debt->credit_amount;
        // Com extrato da administradora, já sugere a parcela cheia estimada e as parcelas que faltam (é só ponto de partida).
        $this->newInstallmentAmount = $this->debt->estimatedFullInstallment() ?? (string) $this->debt->installment_amount;
        $this->remainingInstallments = (string) max(1, $this->debt->statement_remaining ?? $this->debt->term - $this->debt->paidCount());
        $this->contemplationCategoryId = (string) $sugestao['category_id'];
        $this->contemplationSubcategoryId = (string) $sugestao['subcategory_id'];
        $this->markGoalAchieved = true;
        $this->showContemplation = true;
    }

    public function updatedContemplationCategoryId(): void
    {
        $this->contemplationSubcategoryId = '';
    }

    public function saveContemplation(): void
    {
        $dados = $this->validate([
            'contemplatedOn' => ['required', 'date_format:Y-m-d'],
            'contemplationMode' => ['required', ContemplationMode::rule()],
            'bidAmount' => ['nullable', 'string', 'max:20'],
            'creditReleased' => ['nullable', 'string', 'max:20'],
            'newInstallmentAmount' => ['required', 'string', 'max:20'],
            'remainingInstallments' => ['required', 'integer', 'min:1', 'max:480'],
            'contemplationCategoryId' => ['required', 'string'],
            'contemplationSubcategoryId' => ['nullable', 'string'],
        ], [], [
            'contemplatedOn' => 'data da contemplação', 'newInstallmentAmount' => 'nova parcela',
            'remainingInstallments' => 'parcelas restantes', 'contemplationCategoryId' => 'categoria',
        ]);

        try {
            app(DebtService::class)->contemplate($this->debt, [
                'contemplated_on' => $dados['contemplatedOn'], 'mode' => $dados['contemplationMode'],
                'bid_amount' => $dados['bidAmount'] ?: null, 'credit_released' => $dados['creditReleased'] ?: null,
                'new_installment_amount' => $dados['newInstallmentAmount'], 'remaining_installments' => $dados['remainingInstallments'],
                'category_id' => $dados['contemplationCategoryId'], 'subcategory_id' => $dados['contemplationSubcategoryId'] ?: null,
                'mark_goal_achieved' => $this->markGoalAchieved,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('newInstallmentAmount', $e->getMessage());

            return;
        }

        $this->showContemplation = false;
        $this->debt->refresh();
    }

    // ------------------------------------------------------------------ dívida em aberto

    public function openBalance(): void
    {
        $this->resetValidation();
        $this->balanceAmount = (string) $this->debt->current_balance;
        $this->balanceDate = now()->toDateString();
        $this->balanceStatus = $this->debt->status->value;
        $this->showBalance = true;
    }

    public function saveBalance(): void
    {
        $dados = $this->validate([
            'balanceAmount' => ['required', 'string', 'max:20'],
            'balanceDate' => ['required', 'date_format:Y-m-d'],
            'balanceStatus' => ['required', 'in:'.DebtStatus::Open->value.','.DebtStatus::Negotiating->value],
        ], [], ['balanceAmount' => 'saldo', 'balanceDate' => 'data']);

        try {
            app(DebtService::class)->updateBalance(
                $this->debt, $dados['balanceAmount'], CarbonImmutable::createFromFormat('Y-m-d', $dados['balanceDate'])->startOfDay(), DebtStatus::from($dados['balanceStatus']),
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('balanceAmount', $e->getMessage());

            return;
        }

        $this->showBalance = false;
        $this->debt->refresh();
    }

    public function openAgreement(): void
    {
        $sugestao = DebtClassification::suggest(DebtKind::Debt);

        $this->resetValidation();
        $this->agreementTotal = (string) $this->debt->current_balance;
        $this->agreementInstallments = '1';
        $this->agreementFirstDue = now()->addMonth()->toDateString();
        $this->agreementCategoryId = (string) $sugestao['category_id'];
        $this->agreementSubcategoryId = (string) $sugestao['subcategory_id'];
        $this->agreementAccountId = '';
        $this->showAgreement = true;
    }

    public function updatedAgreementCategoryId(): void
    {
        $this->agreementSubcategoryId = '';
    }

    public function saveAgreement(): void
    {
        $dados = $this->validate([
            'agreementTotal' => ['required', 'string', 'max:20'],
            'agreementInstallments' => ['required', 'integer', 'min:1', 'max:480'],
            'agreementFirstDue' => ['required', 'date_format:Y-m-d'],
            'agreementCategoryId' => ['required', 'string'],
            'agreementSubcategoryId' => ['nullable', 'string'],
            'agreementAccountId' => ['nullable', 'string'],
        ], [], [
            'agreementTotal' => 'valor negociado', 'agreementInstallments' => 'parcelas',
            'agreementFirstDue' => 'primeiro vencimento', 'agreementCategoryId' => 'categoria',
        ]);

        try {
            app(DebtService::class)->registerAgreement($this->debt, [
                'total' => $dados['agreementTotal'], 'installments' => $dados['agreementInstallments'], 'first_due_date' => $dados['agreementFirstDue'],
                'category_id' => $dados['agreementCategoryId'], 'subcategory_id' => $dados['agreementSubcategoryId'] ?: null,
                'bank_account_id' => $dados['agreementAccountId'] ?: null,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('agreementTotal', $e->getMessage());

            return;
        }

        $this->showAgreement = false;
        $this->debt->refresh();
    }

    public function markPaidOff(): void
    {
        try {
            app(DebtService::class)->markPaidOff($this->debt);
        } catch (InvalidArgumentException) {
            return;
        }

        $this->debt->refresh();
    }

    // ------------------------------------------------------------------ contrato

    public function openEdit(): void
    {
        $this->resetValidation();
        $this->editName = $this->debt->name;
        $this->editCreditor = (string) $this->debt->creditor;
        $this->editCreditorDocument = (string) $this->debt->creditor_document;
        $this->editContractedOn = (string) $this->debt->contracted_on?->toDateString();
        $this->editNecessity = in_array($this->debt->necessity->value, [Necessity::Essential->value, Necessity::Discretionary->value], true) ? $this->debt->necessity->value : Necessity::Essential->value;
        $this->editCategoryId = (string) $this->debt->category_id;
        $this->editSubcategoryId = (string) $this->debt->subcategory_id;
        $this->editAccountId = (string) $this->debt->bank_account_id;
        $this->editMemberId = (string) $this->debt->member_id;
        $this->editPrivate = $this->debt->is_private;
        $this->editNotes = (string) $this->debt->notes;
        $this->editReclassify = false;
        $this->showEdit = true;
    }

    public function updatedEditCategoryId(): void
    {
        $this->editSubcategoryId = '';
    }

    public function saveEdit(): void
    {
        $dados = $this->validate([
            'editName' => ['required', 'string', 'max:120'],
            'editCreditor' => ['nullable', 'string', 'max:120'],
            'editCreditorDocument' => ['nullable', 'string', 'max:20'],
            'editContractedOn' => ['nullable', 'date_format:Y-m-d'],
            'editNecessity' => [$this->semCategoria() ? 'nullable' : 'required', 'in:'.Necessity::Essential->value.','.Necessity::Discretionary->value],
            'editCategoryId' => [$this->semCategoria() ? 'nullable' : 'required', 'string'],
            'editSubcategoryId' => ['nullable', 'string'],
            'editAccountId' => ['nullable', 'string'],
            'editNotes' => ['nullable', 'string', 'max:2000'],
        ], [], ['editName' => 'nome', 'editCategoryId' => 'categoria']);

        $membro = $this->editMemberId !== '' && $this->membros()->contains('id', $this->editMemberId) ? $this->editMemberId : null;

        try {
            app(DebtService::class)->update($this->debt, [
                'name' => $dados['editName'], 'creditor' => $dados['editCreditor'] ?: null,
                'creditor_document' => $dados['editCreditorDocument'] ?: null, 'contracted_on' => $dados['editContractedOn'] ?: null,
                'bank_account_id' => $dados['editAccountId'] ?: null, 'notes' => $dados['editNotes'] ?: null,
                'member_id' => $membro, 'is_private' => $membro !== null && $this->editPrivate,
            ] + ($this->semCategoria() ? [] : ['necessity' => $dados['editNecessity'], 'category_id' => $dados['editCategoryId'], 'subcategory_id' => $dados['editSubcategoryId'] ?: null]), $this->editReclassify);
        } catch (InvalidArgumentException $e) {
            $this->addError('editCategoryId', $e->getMessage());

            return;
        }

        $this->showEdit = false;
        $this->debt->refresh();
    }

    public function deleteDebt()
    {
        app(DebtService::class)->delete($this->debt);

        return $this->redirectRoute('debts.index');
    }

    /** Consórcio antes da contemplação (investimento "Consórcio") e dívida sem acordo não têm categoria de despesa para trocar. */
    private function semCategoria(): bool
    {
        return $this->debt->isSavingForCredit() || $this->debt->status->isUnscheduled();
    }

    /** @return Collection<int, ProfileMember> */
    private function membros(): Collection
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /** Despesas que podem ter pago a parcela: perto da data e sem estar ligadas a outra parcela, as de valor mais parecido primeiro. */
    private function candidatas(): Collection
    {
        if ($this->linkId === null || ! $this->showLink) {
            return collect();
        }

        $parcela = DebtInstallment::query()->where('debt_id', $this->debt->id)->find($this->linkId);

        if ($parcela === null) {
            return collect();
        }

        return ExpenseRecord::query()
            ->with('category')
            ->whereDoesntHave('debtInstallment')
            ->where('is_refund', false)
            ->whereBetween('expense_date', [$parcela->due_date->copy()->subDays(45)->toDateString(), $parcela->due_date->copy()->addDays(45)->toDateString()])
            ->orderByRaw('ABS(amount - ?)', [(string) $parcela->amount])
            ->orderBy('expense_date')
            ->limit(15)
            ->get();
    }

    public function render()
    {
        // O projeto desliga o carregamento preguiçoso fora de produção: a categoria que a tela mostra vem carregada aqui.
        $this->debt->loadMissing(['category', 'subcategory']);

        $parcelas = $this->debt->installments()->with('expense.category')->get();
        $pagas = $parcelas->where('status', DebtInstallmentStatus::Paid);
        $abertas = $parcelas->where('status', DebtInstallmentStatus::Open);

        return view('livewire.debts.debt-show', [
            'parcelas' => $parcelas,
            'saldo' => $this->debt->balance(),
            'quantidadePagas' => $pagas->count(),
            'totalPago' => Money::sum($pagas->map(fn (DebtInstallment $p) => $p->paid_amount ?? $p->amount)),
            'proxima' => $abertas->first(),
            // Custo da dívida: tudo o que vai além do valor financiado (só quando o contrato separa os juros).
            'custoJuros' => $this->debt->amortization === DebtAmortization::Fixed ? null : bcadd(Money::sum($parcelas->pluck('interest_amount')), Money::sum($parcelas->pluck('fees_amount')), 2),
            'categorias' => ExpenseCategory::available()->whereNull('necessity')->with(['subcategories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])->get(),
            'contas' => BankAccount::query()->where('is_active', true)->orderBy('bank_name')->get(),
            'membros' => $this->membros(),
            'candidatas' => $this->candidatas(),
            'semCategoria' => $this->semCategoria(),
            'emCarta' => $this->debt->isSavingForCredit(),
            'podeSimular' => $this->podeSimular(),
            'basesTaxa' => DebtRateBasis::options(),
            'ultimaPaga' => (int) $parcelas->where('status', DebtInstallmentStatus::Paid)->max('number'),
            'quantidadePuladas' => $parcelas->where('status', DebtInstallmentStatus::Diluted)->count(),
            'ajustes' => $this->debt->isConsortium() ? $this->debt->adjustments()->orderByDesc('applied_on')->orderByDesc('created_at')->get() : collect(),
            'objetivo' => Goal::query()->where('linked_debt_id', $this->debt->id)->first(),
            'modosContemplacao' => ContemplationMode::options(),
        ]);
    }
}
