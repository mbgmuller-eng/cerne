<?php

namespace App\Livewire\CashFlow;

use App\Enums\Necessity;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\IncomeRecord;
use App\Models\ProfileMember;
use App\Support\Money;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tela 3 — Fluxo de Caixa.
 *
 * Lançamentos do mês com filtros por categoria, necessidade e membro.
 *
 * O filtro por membro NÃO substitui a privacidade: os escopos globais já
 * removeram o que o usuário não pode ver antes de qualquer filtro chegar
 * aqui. Este é um recorte de leitura, não um controle de acesso.
 */
#[Layout('components.layouts.app')]
class CashFlowIndex extends Component
{
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

    public function mount(): void
    {
        abort_if(app(ProfileContext::class)->profile() === null, 404);

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
    // Dados
    // -----------------------------------------------------------------

    /** @return Collection<int, ExpenseRecord> */
    public function getExpensesProperty(): Collection
    {
        $query = ExpenseRecord::query()
            ->forPeriod($this->year, $this->month)
            ->with(['category', 'subcategory', 'member', 'creditCard', 'installmentGroup'])
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

        return $query->get();
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
        return view('livewire.cash-flow.cash-flow-index', [
            'expenses' => $this->expenses,
            'incomes' => $this->incomes,
            'totalIncome' => $this->totalIncome,
            'totalExpense' => $this->totalExpense,
            'balance' => $this->balance,
            'byNecessity' => $this->byNecessity,
            'categories' => ExpenseCategory::available()->get(),
            'members' => ProfileMember::query()
                ->where('profile_id', app(ProfileContext::class)->profileId())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'periodLabel' => $this->periodStart()->translatedFormat('F \d\e Y'),
        ]);
    }
}
