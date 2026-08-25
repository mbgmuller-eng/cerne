<?php

namespace App\Livewire\Investments;

use App\Enums\InvestmentSector;
use App\Models\FinancialReserve;
use App\Models\InvestmentPerformance;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\InvestmentTransaction;
use App\Support\Money;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tela 5 — Investimentos, em três abas: Portfólio, Performance e
 * Transações (seção 12 da especificação).
 */
#[Layout('components.layouts.app')]
class InvestmentsIndex extends Component
{
    #[Url]
    public string $tab = 'portfolio';

    public function mount(): void
    {
        abort_if(app(ProfileContext::class)->profile() === null, 404);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['portfolio', 'performance', 'transactions'], true)
            ? $tab
            : 'portfolio';
    }

    /** @return Collection<int, InvestmentRecord> */
    public function getInvestmentsProperty(): Collection
    {
        return InvestmentRecord::query()
            ->active()
            ->with('member')
            ->orderBy('sector')
            ->orderByDesc('current_amount')
            ->get();
    }

    /** Carteira agrupada por setor, como a tela apresenta. */
    public function getBySectorProperty(): Collection
    {
        return $this->investments->groupBy(fn (InvestmentRecord $i) => $i->sector->value);
    }

    public function getTotalProperty(): string
    {
        return Money::sum($this->investments->pluck('current_amount'));
    }

    public function getTotalInvestedProperty(): string
    {
        return Money::sum($this->investments->pluck('invested_amount'));
    }

    public function getTotalGainProperty(): string
    {
        return bcsub($this->total, $this->totalInvested, 2);
    }

    /** @return Collection<int, FinancialReserve> */
    public function getReservesProperty(): Collection
    {
        return FinancialReserve::query()->with('member', 'linkedInvestment')->get();
    }

    /**
     * Histórico mensal (mais antigo primeiro) dos ativos de previdência —
     * é o que desenha o gráfico do "card de contrato". Só busca pra
     * quem tem `sector = retirement`; os demais setores não usam gráfico.
     *
     * @return array<string, list<float>> investment_id => valores cronológicos
     */
    public function getSnapshotHistoryProperty(): array
    {
        $idsAposentadoria = $this->investments
            ->where('sector', InvestmentSector::Retirement)
            ->pluck('id');

        if ($idsAposentadoria->isEmpty()) {
            return [];
        }

        return InvestmentSnapshot::query()
            ->whereIn('investment_id', $idsAposentadoria)
            ->orderBy('year')->orderBy('month')
            ->get(['investment_id', 'amount'])
            ->groupBy('investment_id')
            ->map(fn (Collection $grupo) => $grupo->pluck('amount')->map(fn ($v) => (float) $v)->all())
            ->all();
    }

    /** @return Collection<int, InvestmentPerformance> */
    public function getPerformanceProperty(): Collection
    {
        return InvestmentPerformance::query()
            ->with('investment')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(24)
            ->get();
    }

    /** @return Collection<int, InvestmentTransaction> */
    public function getTransactionsProperty(): Collection
    {
        return InvestmentTransaction::query()
            ->with('investment', 'member')
            ->orderByDesc('operation_date')
            ->limit(50)
            ->get();
    }

    public function render()
    {
        return view('livewire.investments.investments-index', [
            'bySector' => $this->bySector,
            'total' => $this->total,
            'totalInvested' => $this->totalInvested,
            'totalGain' => $this->totalGain,
            'reserves' => $this->reserves,
            'performance' => $this->performance,
            'transactions' => $this->transactions,
            'snapshotHistory' => $this->snapshotHistory,
        ]);
    }
}
