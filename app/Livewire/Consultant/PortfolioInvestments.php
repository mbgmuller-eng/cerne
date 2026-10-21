<?php

namespace App\Livewire\Consultant;

use App\Livewire\Concerns\FiltersInvestmentGrowth;
use App\Models\InvestmentRecord;
use App\Services\ConsultantPortfolioService;
use App\Support\CurrencyBreakdown;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Investimentos de TODOS os clientes ativos do consultor, agrupados em
 * cliente → (membro, quando há mais de um dono no mesmo cliente) →
 * instituição → ativos — mesmo corte de PortfolioInsurance, só que sem
 * o nível de "tipo" (não fazia sentido aqui: o filtro relevante já é
 * por instituição, que vira o nível interno de agrupamento).
 */
#[Layout('components.layouts.app')]
class PortfolioInvestments extends Component
{
    use FiltersInvestmentGrowth;

    #[Url]
    public string $instituicao = '';

    /** 'reais' ou 'moedas': a mesma escolha do painel da carteira (fica na sessão). */
    public string $exibicao = 'reais';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);

        $this->exibicao = session('carteira_exibicao') === 'moedas' ? 'moedas' : 'reais';
    }

    public function exibir(string $modo): void
    {
        $this->exibicao = $modo === 'moedas' ? 'moedas' : 'reais';
        session(['carteira_exibicao' => $this->exibicao]);
    }

    public function render(ConsultantPortfolioService $portfolio)
    {
        $todos = $portfolio->allActiveInvestments(auth()->user());

        $instituicoes = $todos
            ->pluck('investment.institution')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $linhas = $this->instituicao === ''
            ? $todos
            : $todos->filter(fn (array $linha): bool => $linha['investment']->institution === $this->instituicao);

        $crescimento = $this->computeGrowthPercentages($linhas->pluck('investment'));
        $linhas = $linhas->map(fn (array $l): array => $l + ['pct' => $crescimento[$l['investment']->id]['pct'] ?? null]);

        return view('livewire.consultant.portfolio-investments', [
            'grouped' => $this->group($linhas),
            // O total de todos os clientes mostrados, por moeda — o resumo do topo no modo "Por moeda".
            'porMoeda' => self::currencies($linhas->pluck('investment')),
            'temMoeda' => CurrencyBreakdown::hasForeign(self::currencies($todos->pluck('investment'))),
            'instituicoes' => $instituicoes,
            'totalGeral' => $todos->count(),
            'growthPeriod' => $this->growthPeriod,
            'growthPeriodOptions' => $this->growthPeriodOptions(),
        ]);
    }

    /**
     * Soma por moeda (reais, USDT, BTC, ETH) de uma lista de investimentos já carregada nesta tela.
     *
     * @param  Collection<int, InvestmentRecord>  $investimentos
     * @return list<array{currency: string, native: string, brl: string}>
     */
    public static function currencies(Collection $investimentos): array
    {
        return CurrencyBreakdown::rows($investimentos->groupBy('currency')->map(fn (Collection $g, $moeda) => [
            'currency' => (string) $moeda,
            'brl' => Money::sum($g->pluck('current_amount')),
            'native' => $moeda === 'BRL' ? null : $g->reduce(fn (string $c, InvestmentRecord $i) => bcadd($c, (string) $i->native_amount, 10), '0'),
        ])->values()->all());
    }

    /**
     * `quantidade`/`total` ficam no resumo do card — dá pra saber o
     * tamanho da carteira daquele cliente sem precisar abrir o
     * acordeão (ver getGroupedProperty() equivalente em InsuranceIndex).
     *
     * @param  Collection<int, array{investment: InvestmentRecord, client_name: string, member_name: ?string, pct: ?float}>  $linhas
     * @return Collection<string, array{separarPorMembro: bool, quantidade: int, total: string, crescimento: array{pct: ?float, ganho: ?string}, profile_id: string, membros: Collection}>
     */
    private function group(Collection $linhas): Collection
    {
        return $linhas
            ->groupBy('client_name')
            ->sortKeys()
            ->map(function (Collection $doCliente) {
                $separarPorMembro = $doCliente->map(fn (array $l) => $l['investment']->member_id)->unique()->count() > 1;

                $porMembro = $separarPorMembro
                    ? $doCliente->groupBy(fn (array $l) => $l['investment']->member_id)
                    : collect(['todos' => $doCliente]);

                return [
                    'separarPorMembro' => $separarPorMembro,
                    'quantidade' => $doCliente->count(),
                    'total' => Money::sum($doCliente->map(fn (array $l) => $l['investment']->current_amount)),
                    'moedas' => self::currencies($doCliente->pluck('investment')),
                    // Crescimento da carteira DESTE cliente no período
                    // escolhido — soma dos ativos dele, não média das
                    // percentuais individuais (ver computeGrowthTotal()).
                    'crescimento' => $this->computeGrowthTotal($doCliente->pluck('investment')),
                    // Mesmo perfil pra qualquer ativo deste cliente — dá pra
                    // pegar de qualquer linha. Usado pro botão único "Abrir
                    // perfil" no cabeçalho do card, em vez de repetir um
                    // por ativo (era sempre o mesmo destino).
                    'profile_id' => $doCliente->first()['investment']->profile_id,
                    'membros' => $porMembro->map(fn (Collection $doMembro) => [
                        'nome' => $doMembro->first()['member_name'],
                        'instituicoes' => $doMembro->groupBy(fn (array $l) => $l['investment']->institution ?? 'Sem instituição'),
                    ])->values(),
                ];
            });
    }
}
