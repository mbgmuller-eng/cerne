<?php

namespace App\Livewire\Concerns;

use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Filtro de período pro "Ganho" (%) de cada investimento — telas do
 * cliente e do consultor compartilham a mesma lógica (o consultor pediu
 * "esse filtro já existir em investimentos nas páginas dos usuários").
 *
 * "Desde o início" continua usando invested_amount (custo de aquisição
 * acumulado, sem data — é o que InvestmentRecord::gainPercentage() já
 * fazia). Os outros três comparam fotos mensais de InvestmentSnapshot,
 * tiradas sempre no dia 1 (InvestmentSnapshotService::captureMonth()):
 * "este ano" usa a foto de janeiro (≈ 31/dez anterior); "este mês", a
 * foto do dia 1 do mês corrente; "comparar dois meses", a foto do dia 1
 * de cada mês escolhido — o próprio usuário compara ponto a ponto, sem
 * usar "agora" em nenhum dos dois lados.
 *
 * Onde falta foto (ativo criado depois daquele mês, ou mês sem captura),
 * o cálculo devolve null — a tela mostra "—", nunca um número errado.
 *
 * withoutProfileScope() nas duas consultas de foto porque quem chama já
 * filtra por uma lista de investment_id previamente autorizada (do
 * próprio perfil, ou dos perfis vinculados ao consultor) — não abre
 * escopo novo, só evita que o profile_id ATIVO no momento (que pra tela
 * do consultor não é nenhum perfil de cliente específico) descarte fotos
 * legítimas de outro perfil.
 */
trait FiltersInvestmentGrowth
{
    #[Url]
    public string $growthPeriod = 'inicio';

    #[Url]
    public string $growthMonthA = '';

    #[Url]
    public string $growthMonthB = '';

    /** @return array<string, string> valor => rótulo, pro <select> do filtro */
    public function growthPeriodOptions(): array
    {
        return [
            'inicio' => 'Desde o início',
            'ano' => 'Este ano',
            'mes' => 'Este mês',
            'comparar' => 'Comparar dois meses',
        ];
    }

    /**
     * Nome com "compute" de propósito — InvestmentsIndex expõe um
     * computed property chamado growthPercentages (getGrowthPercentagesProperty),
     * e $this->growthPercentages (sem parênteses) e $this->growthPercentages()
     * (com parênteses, este método) são coisas diferentes em PHP, mas
     * fica confuso de ler com o mesmo nome — por isso o prefixo.
     *
     * Devolve também o delta em R$ (`ganho`), não só o percentual — a
     * tela do cliente mostra os dois juntos ("+R$ 500 (+15%)"), e os
     * dois precisam vir do MESMO par de pontos (base do período
     * escolhido, não sempre "desde o início"), senão o valor em R$ e o
     * percentual contariam histórias diferentes lado a lado.
     *
     * @param  Collection<int, InvestmentRecord>  $investimentos
     * @return array<string, array{pct: ?float, ganho: ?string}> investment_id => (null = sem foto suficiente)
     */
    protected function computeGrowthPercentages(Collection $investimentos): array
    {
        return collect($this->growthPoints($investimentos))
            ->map(fn (array $p) => [
                'pct' => InvestmentRecord::percentageChange($p['de'], $p['para']),
                'ganho' => $p['de'] !== null && $p['para'] !== null ? bcsub($p['para'], $p['de'], 2) : null,
            ])
            ->all();
    }

    /**
     * Crescimento da CARTEIRA inteira informada (soma, não média das
     * percentuais individuais — %s não se somam nem se tiram média
     * direto, cada ativo tem uma base diferente). Só entram na soma os
     * ativos com os dois pontos disponíveis (mesma regra de "sem foto,
     * sem número" da versão por ativo); um ativo sem base nenhuma não
     * conta nem a favor nem contra.
     *
     * @param  Collection<int, InvestmentRecord>  $investimentos
     * @return array{pct: ?float, ganho: ?string}
     */
    protected function computeGrowthTotal(Collection $investimentos): array
    {
        $somaDe = '0.00';
        $somaPara = '0.00';
        $contribuiu = false;

        foreach ($this->growthPoints($investimentos) as $p) {
            if ($p['de'] === null || $p['para'] === null) {
                continue;
            }

            $somaDe = bcadd($somaDe, $p['de'], 2);
            $somaPara = bcadd($somaPara, $p['para'], 2);
            $contribuiu = true;
        }

        if (! $contribuiu) {
            return ['pct' => null, 'ganho' => null];
        }

        return [
            'pct' => InvestmentRecord::percentageChange($somaDe, $somaPara),
            'ganho' => bcsub($somaPara, $somaDe, 2),
        ];
    }

    /**
     * Os dois pontos de comparação de cada ativo, conforme o período —
     * base compartilhada por computeGrowthPercentages() (por ativo) e
     * computeGrowthTotal() (soma da carteira), pra não buscar a mesma
     * foto duas vezes nem duplicar a regra de qual foto usar.
     *
     * @param  Collection<int, InvestmentRecord>  $investimentos
     * @return array<string, array{de: ?string, para: ?string}>
     */
    private function growthPoints(Collection $investimentos): array
    {
        if ($this->growthPeriod === 'inicio') {
            return $investimentos->mapWithKeys(fn (InvestmentRecord $i) => [
                $i->id => [
                    'de' => $i->invested_amount !== null && bccomp($i->invested_amount, '0', 2) > 0 ? $i->invested_amount : null,
                    'para' => $i->current_amount,
                ],
            ])->all();
        }

        $ids = $investimentos->pluck('id');
        $inicio = $this->growthSnapshotAmounts($ids, $this->growthPeriodStart());
        $fim = $this->growthPeriod === 'comparar'
            ? $this->growthSnapshotAmounts($ids, $this->growthMonthPoint($this->growthMonthB))
            : null;

        return $investimentos->mapWithKeys(fn (InvestmentRecord $i) => [
            $i->id => [
                'de' => $inicio[$i->id] ?? null,
                'para' => $fim === null ? $i->current_amount : ($fim[$i->id] ?? null),
            ],
        ])->all();
    }

    /** @return ?array{year: int, month: int} */
    private function growthPeriodStart(): ?array
    {
        $hoje = now();

        return match ($this->growthPeriod) {
            'ano' => ['year' => $hoje->year, 'month' => 1],
            'mes' => ['year' => $hoje->year, 'month' => $hoje->month],
            'comparar' => $this->growthMonthPoint($this->growthMonthA),
            default => null,
        };
    }

    /** Converte o valor de um <input type="month"> ("2026-05") em ano/mês. */
    private function growthMonthPoint(string $valor): ?array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $valor, $m)) {
            return null;
        }

        return ['year' => (int) $m[1], 'month' => (int) $m[2]];
    }

    /**
     * @param  Collection<int, string>  $investmentIds
     * @param  ?array{year: int, month: int}  $ponto
     * @return array<string, string> investment_id => amount
     */
    private function growthSnapshotAmounts(Collection $investmentIds, ?array $ponto): array
    {
        if ($ponto === null || $investmentIds->isEmpty()) {
            return [];
        }

        return InvestmentSnapshot::withoutProfileScope()
            ->whereIn('investment_id', $investmentIds)
            ->where('year', $ponto['year'])
            ->where('month', $ponto['month'])
            ->pluck('amount', 'investment_id')
            ->all();
    }
}
