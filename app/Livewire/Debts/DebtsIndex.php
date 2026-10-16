<?php

namespace App\Livewire\Debts;

use App\Enums\AdjustmentIndex;
use App\Enums\ConsortiumType;
use App\Enums\DebtAmortization;
use App\Enums\DebtKind;
use App\Enums\DebtRateBasis;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\ExpenseCategory;
use App\Models\Goal;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Services\DebtService;
use App\Support\Debts\DebtClassification;
use App\Support\Money;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dívidas: o que se deve (financiamentos, empréstimos, consórcios contemplados e dívidas em aberto), o que vence neste mês, as
 * cartas de consórcio e a lista de contratos. Cada contrato abre a tela com o cronograma de parcelas (ver DebtShow).
 */
#[Layout('components.layouts.app')]
class DebtsIndex extends Component
{
    public bool $showForm = false;

    public string $kind = 'financing';

    public string $asset = DebtClassification::PROPERTY;

    public string $name = '';

    public string $creditor = '';

    public string $amortization = 'sac';

    public string $principal = '';

    public string $rate = '';

    public string $rateBasis = 'annual_nominal';

    public string $term = '';

    public string $firstDueDate = '';

    public string $installmentAmount = '';

    public string $monthlyFees = '';

    public string $paidBefore = '0';

    public string $necessity = 'essential';

    public string $categoryId = '';

    public string $subcategoryId = '';

    public string $bankAccountId = '';

    public string $memberId = '';

    public bool $isPrivate = false;

    public string $notes = '';

    // Consórcio
    public string $consortiumType = 'vehicle';

    public string $consortiumGroup = '';

    public string $creditAmount = '';

    public bool $isFlex = false;

    public string $adjustmentIndex = 'incc';

    public string $adjustmentEvery = '12';

    public string $adjustmentFirstAfter = '12';

    /** '' (nenhum), o id de um objetivo existente ou 'new' (criar um objetivo para este consórcio). */
    public string $goalChoice = '';

    public string $goalName = '';

    // Dívida em aberto
    public string $currentBalance = '';

    public string $balanceDate = '';

    public string $openStatus = 'open';

    public function mount(): void
    {
        // Mesmo bloqueio das demais telas financeiras: corretor só entra em Seguros.
        abort_if(auth()->user()?->isBroker(), 403);
    }

    public function openForm(): void
    {
        $this->resetValidation();
        $this->reset(
            'name', 'creditor', 'principal', 'rate', 'term', 'firstDueDate', 'installmentAmount', 'monthlyFees', 'paidBefore', 'bankAccountId', 'isPrivate', 'notes',
            'consortiumGroup', 'creditAmount', 'isFlex', 'goalChoice', 'goalName', 'currentBalance',
        );
        $this->paidBefore = '0';
        $this->kind = 'financing';
        $this->asset = DebtClassification::PROPERTY;
        $this->amortization = 'sac';
        $this->rateBasis = 'annual_nominal';
        $this->necessity = 'essential';
        $this->consortiumType = 'vehicle';
        $this->adjustmentIndex = 'incc';
        $this->adjustmentEvery = '12';
        $this->adjustmentFirstAfter = '12';
        $this->balanceDate = now()->toDateString();
        $this->openStatus = 'open';
        $this->memberId = (string) app(ProfileContext::class)->memberId();
        $this->applySuggestion();
        $this->showForm = true;
    }

    public function updatedKind(): void
    {
        $this->amortization = $this->kind === 'loan' ? 'fixed' : 'sac';
        $this->applySuggestion();
    }

    public function updatedAsset(): void
    {
        $this->applySuggestion();
    }

    public function updatedCategoryId(): void
    {
        $this->subcategoryId = '';
    }

    /** Pré-seleciona a categoria das parcelas pelo tipo (e pelo bem financiado). A pessoa pode trocar. */
    private function applySuggestion(): void
    {
        if (in_array($this->kind, ['consortium', 'debt'], true)) {
            // Consórcio ainda não contemplado é investimento (categoria fixa); dívida em aberto só ganha categoria no acordo.
            $this->categoryId = '';
            $this->subcategoryId = '';

            return;
        }

        $sugestao = DebtClassification::suggest(DebtKind::from($this->kind), $this->kind === 'financing' ? $this->asset : null);

        $this->categoryId = (string) $sugestao['category_id'];
        $this->subcategoryId = (string) $sugestao['subcategory_id'];
    }

    public function save(): void
    {
        // A taxa chega como o brasileiro digita (10,5); o validador numérico espera o ponto.
        $this->rate = str_replace(',', '.', trim($this->rate));

        match ($this->kind) {
            'consortium' => $this->saveConsortium(),
            'debt' => $this->saveOpenDebt(),
            default => $this->saveScheduled(),
        };
    }

    private function saveScheduled(): void
    {
        $emCalculo = $this->amortization !== 'fixed';

        $dados = $this->validate([
            'kind' => ['required', 'in:financing,loan'],
            'name' => ['required', 'string', 'max:120'],
            'creditor' => ['nullable', 'string', 'max:120'],
            'amortization' => ['required', DebtAmortization::rule()],
            'principal' => [$emCalculo ? 'required' : 'nullable', 'string', 'max:20'],
            'rate' => [$emCalculo ? 'required' : 'nullable', 'numeric', 'min:0', 'max:1000'],
            'rateBasis' => [$emCalculo ? 'required' : 'nullable', DebtRateBasis::rule()],
            'term' => ['required', 'integer', 'min:1', 'max:480'],
            'firstDueDate' => ['required', 'date_format:Y-m-d'],
            'installmentAmount' => [$emCalculo ? 'nullable' : 'required', 'string', 'max:20'],
            'monthlyFees' => ['nullable', 'string', 'max:20'],
            'paidBefore' => ['required', 'integer', 'min:0', 'lte:term'],
            'necessity' => ['required', 'in:'.Necessity::Essential->value.','.Necessity::Discretionary->value],
            'categoryId' => ['required', 'string'],
            'subcategoryId' => ['nullable', 'string'],
            'bankAccountId' => ['nullable', 'string'],
            'memberId' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'paidBefore.lte' => 'As parcelas já pagas não podem passar do prazo.',
        ], $this->attributeNames());

        $this->persist(fn () => app(DebtService::class)->create([
            'kind' => $dados['kind'], 'name' => $dados['name'], 'creditor' => $dados['creditor'] ?: null,
            'amortization' => $dados['amortization'], 'principal' => $dados['principal'] ?: null,
            'rate' => $emCalculo ? $dados['rate'] : null, 'rate_basis' => $emCalculo ? $dados['rateBasis'] : null,
            'term' => $dados['term'], 'first_due_date' => $dados['firstDueDate'],
            'installment_amount' => $dados['installmentAmount'] ?: null, 'monthly_fees' => $dados['monthlyFees'] ?: 0,
            'paid_before' => $dados['paidBefore'], 'necessity' => $dados['necessity'],
            'category_id' => $dados['categoryId'], 'subcategory_id' => $dados['subcategoryId'] ?: null,
            'bank_account_id' => $dados['bankAccountId'] ?: null,
            'member_id' => $this->resolvedMember(), 'is_private' => $this->isPrivate, 'notes' => $dados['notes'] ?: null,
        ]));
    }

    private function saveConsortium(): void
    {
        $comReajuste = $this->adjustmentIndex !== '';

        $dados = $this->validate([
            'consortiumType' => ['required', ConsortiumType::rule()],
            'name' => ['nullable', 'string', 'max:120'],
            'creditor' => ['nullable', 'string', 'max:120'],
            'consortiumGroup' => ['nullable', 'string', 'max:40'],
            'creditAmount' => ['required', 'string', 'max:20'],
            'installmentAmount' => ['required', 'string', 'max:20'],
            'term' => ['required', 'integer', 'min:1', 'max:480'],
            'firstDueDate' => ['required', 'date_format:Y-m-d'],
            'paidBefore' => ['required', 'integer', 'min:0', 'lte:term'],
            'adjustmentIndex' => ['nullable', AdjustmentIndex::rule()],
            'adjustmentEvery' => [$comReajuste ? 'required' : 'nullable', 'integer', 'min:1', 'max:60'],
            'adjustmentFirstAfter' => [$comReajuste ? 'required' : 'nullable', 'integer', 'min:1', 'max:120'],
            'goalChoice' => ['nullable', 'string'],
            'goalName' => [$this->goalChoice === 'new' ? 'required' : 'nullable', 'string', 'max:120'],
            'bankAccountId' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['paidBefore.lte' => 'As parcelas já pagas não podem passar do prazo.'], $this->attributeNames());

        $this->persist(fn () => app(DebtService::class)->createConsortium([
            'consortium_type' => $dados['consortiumType'], 'name' => $dados['name'] ?: null, 'creditor' => $dados['creditor'] ?: null,
            'consortium_group' => $dados['consortiumGroup'] ?: null, 'credit_amount' => $dados['creditAmount'],
            'installment_amount' => $dados['installmentAmount'], 'term' => $dados['term'], 'first_due_date' => $dados['firstDueDate'],
            'paid_before' => $dados['paidBefore'], 'is_flex' => $this->isFlex,
            'adjustment_index' => $comReajuste ? $dados['adjustmentIndex'] : null,
            'adjustment_every_months' => $comReajuste ? $dados['adjustmentEvery'] : null,
            'adjustment_first_after_months' => $comReajuste ? $dados['adjustmentFirstAfter'] : null,
            'bank_account_id' => $dados['bankAccountId'] ?: null, 'member_id' => $this->resolvedMember(), 'is_private' => $this->isPrivate,
            'notes' => $dados['notes'] ?: null,
            'goal_id' => ! in_array($this->goalChoice, ['', 'new'], true) ? $this->goalChoice : null,
            'goal_name' => $this->goalChoice === 'new' ? $dados['goalName'] : null,
        ]));
    }

    private function saveOpenDebt(): void
    {
        $dados = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'creditor' => ['nullable', 'string', 'max:120'],
            'principal' => ['nullable', 'string', 'max:20'],
            'currentBalance' => ['required', 'string', 'max:20'],
            'balanceDate' => ['required', 'date_format:Y-m-d'],
            'openStatus' => ['required', 'in:open,negotiating'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], $this->attributeNames());

        $this->persist(fn () => app(DebtService::class)->createOpenDebt([
            'name' => $dados['name'], 'creditor' => $dados['creditor'] ?: null, 'principal' => $dados['principal'] ?: null,
            'current_balance' => $dados['currentBalance'], 'balance_updated_on' => $dados['balanceDate'], 'status' => $dados['openStatus'],
            'notes' => $dados['notes'] ?: null, 'member_id' => $this->resolvedMember(), 'is_private' => $this->isPrivate,
        ]));
    }

    /** Roda o cadastro e leva à tela do contrato; erro de regra de negócio volta como mensagem no formulário. */
    private function persist(callable $criar): void
    {
        try {
            $divida = $criar();
        } catch (InvalidArgumentException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->showForm = false;

        $this->redirectRoute('debts.show', $divida, navigate: false);
    }

    private function resolvedMember(): ?string
    {
        return $this->memberId !== '' && $this->membros()->contains('id', $this->memberId) ? $this->memberId : null;
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        return [
            'name' => 'nome', 'creditor' => 'credor', 'principal' => 'valor', 'rate' => 'taxa', 'rateBasis' => 'base da taxa',
            'term' => 'prazo', 'firstDueDate' => 'primeiro vencimento', 'installmentAmount' => 'valor da parcela', 'paidBefore' => 'parcelas já pagas',
            'categoryId' => 'categoria', 'creditAmount' => 'valor da carta', 'consortiumType' => 'tipo do consórcio',
            'adjustmentEvery' => 'periodicidade do reajuste', 'adjustmentFirstAfter' => 'prazo do primeiro reajuste', 'goalName' => 'nome do objetivo',
            'currentBalance' => 'saldo atual', 'balanceDate' => 'data do saldo', 'openStatus' => 'situação',
        ];
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

    public function render(DebtService $dividas)
    {
        $resumo = $dividas->summary();

        $receitasDoMes = (string) IncomeRecord::query()->forPeriod(now()->year, now()->month)->sum('amount');

        $contratos = Debt::query()->with(['category', 'subcategory', 'installments' => fn ($q) => $q->where('status', 'open')->orderBy('number')->limit(1)])
            ->orderByRaw("status = 'paid_off'")->orderBy('name')->get();

        return view('livewire.debts.debts-index', [
            'resumo' => $resumo,
            'comprometido' => bccomp(Money::parse($receitasDoMes), '0', 2) > 0 ? Money::percentageOf($resumo['month_total'], $receitasDoMes) : null,
            'financiamentos' => $contratos->where('kind', DebtKind::Financing)->values(),
            'emprestimos' => $contratos->where('kind', DebtKind::Loan)->values(),
            'consorcios' => $contratos->where('kind', DebtKind::Consortium)->values(),
            'emAberto' => $contratos->where('kind', DebtKind::Debt)->values(),
            'categorias' => ExpenseCategory::available()->whereNull('necessity')->with(['subcategories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])->get(),
            'contas' => BankAccount::query()->where('is_active', true)->orderBy('bank_name')->get(),
            'membros' => $this->membros(),
            'objetivos' => Goal::query()->active()->whereNull('linked_debt_id')->byPriority()->get(),
            'bens' => DebtClassification::assets(),
            'sistemas' => DebtAmortization::options(),
            'bases' => DebtRateBasis::options(),
            'tiposConsorcio' => ConsortiumType::options(),
            'indices' => AdjustmentIndex::options(),
        ]);
    }
}
