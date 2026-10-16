<?php

namespace App\Livewire\Debts;

use App\Enums\DebtAmortization;
use App\Enums\DebtKind;
use App\Enums\DebtRateBasis;
use App\Enums\DebtStatus;
use App\Enums\Necessity;
use App\Models\BankAccount;
use App\Models\Debt;
use App\Models\ExpenseCategory;
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
 * Dívidas: o que se deve (financiamentos e empréstimos), o que vence neste mês e a lista de contratos.
 * Cada contrato abre a tela com o cronograma de parcelas (ver DebtShow).
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

    public function mount(): void
    {
        // Mesmo bloqueio das demais telas financeiras: corretor só entra em Seguros.
        abort_if(auth()->user()?->isBroker(), 403);
    }

    public function openForm(): void
    {
        $this->resetValidation();
        $this->reset('name', 'creditor', 'principal', 'rate', 'term', 'firstDueDate', 'installmentAmount', 'monthlyFees', 'paidBefore', 'bankAccountId', 'isPrivate', 'notes');
        $this->paidBefore = '0';
        $this->kind = 'financing';
        $this->asset = DebtClassification::PROPERTY;
        $this->amortization = 'sac';
        $this->rateBasis = 'annual_nominal';
        $this->necessity = 'essential';
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
        $sugestao = DebtClassification::suggest(DebtKind::from($this->kind), $this->kind === 'financing' ? $this->asset : null);

        $this->categoryId = (string) $sugestao['category_id'];
        $this->subcategoryId = (string) $sugestao['subcategory_id'];
    }

    public function save(): void
    {
        // A taxa chega como o brasileiro digita (10,5); o validador numérico espera o ponto.
        $this->rate = str_replace(',', '.', trim($this->rate));

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
        ], [
            'name' => 'nome', 'creditor' => 'credor', 'principal' => 'valor financiado', 'rate' => 'taxa', 'rateBasis' => 'base da taxa',
            'term' => 'prazo', 'firstDueDate' => 'primeiro vencimento', 'installmentAmount' => 'valor da parcela', 'paidBefore' => 'parcelas já pagas',
            'categoryId' => 'categoria',
        ]);

        try {
            $divida = app(DebtService::class)->create([
                'kind' => $dados['kind'], 'name' => $dados['name'], 'creditor' => $dados['creditor'] ?: null,
                'amortization' => $dados['amortization'], 'principal' => $dados['principal'] ?: null,
                'rate' => $emCalculo ? $dados['rate'] : null, 'rate_basis' => $emCalculo ? $dados['rateBasis'] : null,
                'term' => $dados['term'], 'first_due_date' => $dados['firstDueDate'],
                'installment_amount' => $dados['installmentAmount'] ?: null, 'monthly_fees' => $dados['monthlyFees'] ?: 0,
                'paid_before' => $dados['paidBefore'], 'necessity' => $dados['necessity'],
                'category_id' => $dados['categoryId'], 'subcategory_id' => $dados['subcategoryId'] ?: null,
                'bank_account_id' => $dados['bankAccountId'] ?: null,
                'member_id' => $this->memberId !== '' && $this->membros()->contains('id', $this->memberId) ? $this->memberId : null,
                'is_private' => $this->isPrivate, 'notes' => $dados['notes'] ?: null,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->showForm = false;

        $this->redirectRoute('debts.show', $divida, navigate: false);
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
            'categorias' => ExpenseCategory::available()->whereNull('necessity')->with(['subcategories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])->get(),
            'contas' => BankAccount::query()->where('is_active', true)->orderBy('bank_name')->get(),
            'membros' => $this->membros(),
            'bens' => DebtClassification::assets(),
            'sistemas' => DebtAmortization::options(),
            'bases' => DebtRateBasis::options(),
        ]);
    }
}
