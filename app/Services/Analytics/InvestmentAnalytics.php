<?php

namespace App\Services\Analytics;

use App\Models\InvestmentRecord;
use App\Support\Charts\ChartPalette;
use App\Support\Charts\SvgCharts;
use App\Support\Money;

/**
 * Análises dos investimentos: onde o patrimônio está concentrado. Soma o valor em reais (`current_amount`, sempre em reais, também nas moedas)
 * dos investimentos ativos que a pessoa enxerga, por instituição, em SQL. Investimento privado do cônjuge não entra no total de quem não o vê.
 */
class InvestmentAnalytics
{
    /** Mais barras que isso vira uma lista comprida; o resto se soma em "Outras". */
    private const MAX_BARRAS = 8;

    /** @return array<string, mixed> */
    public function report(ChartPalette $p): array
    {
        $linhas = InvestmentRecord::query()->where('is_active', true)
            ->selectRaw('institution, SUM(current_amount) as total, COUNT(*) as qtd')->groupBy('institution')->orderByDesc('total')->get();

        $total = Money::sum($linhas->pluck('total'));
        $itens = $linhas->map(fn ($l) => [
            'nome' => trim((string) $l->institution) !== '' ? (string) $l->institution : 'Sem instituição',
            'valor' => Money::parse($l->total),
            'qtd' => (int) $l->qtd,
        ])->all();

        $kpis = [['Patrimônio investido', Money::format($total), 'soma dos investimentos ativos']];
        $secoes = [];

        if ($itens !== [] && bccomp($total, '0', 2) > 0) {
            $maior = $itens[0];
            $kpis[] = ['Maior instituição', number_format(Money::percentageOf($maior['valor'], $total), 1, ',', '.').'%', $maior['nome']];
            $kpis[] = ['Instituições', (string) count($itens), 'com investimento ativo'];

            $mostrar = array_slice($itens, 0, self::MAX_BARRAS);
            $resto = array_slice($itens, self::MAX_BARRAS);
            if ($resto !== []) {
                $mostrar[] = ['nome' => 'Outras ('.count($resto).')', 'valor' => Money::sum(array_column($resto, 'valor')), 'qtd' => array_sum(array_column($resto, 'qtd'))];
            }

            $barras = array_map(function (array $i) use ($total): array {
                $pct = number_format(Money::percentageOf($i['valor'], $total), 1, ',', '.').'%';

                return [$i['nome'], (float) $i['valor'], $pct, $i['nome'].': '.Money::format($i['valor']).' ('.$pct.')'];
            }, $mostrar);

            $secoes[] = [
                'id' => 'instituicoes',
                'titulo' => 'Onde o patrimônio está concentrado',
                'sub' => 'Participação de cada instituição nos '.Money::format($total).' investidos. A maior fica em destaque.',
                'svg' => SvgCharts::barrasHorizontais($barras, $p, true, 640, 'Barras horizontais com a participação de cada instituição no patrimônio'),
                'legenda' => [],
                'tabela' => [
                    'colunas' => ['Instituição', 'Valor', 'Parte', 'Investimentos'],
                    'linhas' => array_map(fn (array $i) => [$i['nome'], Money::format($i['valor']), number_format(Money::percentageOf($i['valor'], $total), 1, ',', '.').'%', (string) $i['qtd']], $mostrar),
                ],
                'nota' => 'Concentração em uma única instituição aumenta o risco de depender dela. O Cerne mostra a distribuição, mas não recomenda quanto manter em cada uma.',
            ];
        }

        return [
            'titulo' => 'Análises de investimentos',
            'periodo' => 'Posição de hoje',
            'kpis' => $kpis,
            'secoes' => $secoes,
        ];
    }
}
