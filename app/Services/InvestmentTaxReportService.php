<?php

namespace App\Services;

use App\Enums\AssetClass;
use App\Enums\TransactionType;
use App\Models\InvestmentRecord;
use App\Models\InvestmentTransaction;
use App\Support\Money;
use App\Support\PositionReplay;
use Illuminate\Support\Collection;

/**
 * Apoio à declaração de imposto de renda de um ano, a partir das movimentações do perfil ativo: o que se tinha em 31/12 (e em
 * 31/12 do ano anterior), os proventos recebidos e as vendas do ano com o resultado de cada uma.
 *
 * É conferência, não apuração oficial: não calcula DARF, não compensa prejuízo e não separa day trade. Só olha o que está
 * registrado no Cerne. Respeita a privacidade do perfil (as consultas usam os escopos de sempre).
 */
class InvestmentTaxReportService
{
    /** Limite mensal de vendas abaixo do qual o ganho é isento, por classe do ativo. Ação: R$ 20 mil; cripto: R$ 35 mil. */
    private const EXEMPTION_LIMITS = [
        'acao' => '20000.00',
        'cripto' => '35000.00',
    ];

    /**
     * @return array{
     *     year: int,
     *     positions: list<array{investment: InvestmentRecord, quantity: string, average_price: string, cost: string, previous_cost: string}>,
     *     positions_cost: string, previous_cost: string,
     *     incomes: list<array{investment: InvestmentRecord, kind: string, label: string, gross: string, tax: string, net: string}>,
     *     income_totals: array<string, array{label: string, gross: string, tax: string, net: string}>,
     *     months: list<array{month: int, groups: list<array{key: string, label: string, sold: string, result: string, limit: ?string, exempt: bool}>, sales: list<array<string, mixed>>}>,
     *     has_data: bool
     * }
     */
    public function forYear(int $year, ?string $memberId = null): array
    {
        $ativos = InvestmentRecord::query()
            ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->get()
            ->keyBy('id');

        $movimentacoes = $ativos->isEmpty() ? collect() : InvestmentTransaction::query()
            ->whereIn('investment_id', $ativos->keys())
            ->whereDate('operation_date', '<=', "{$year}-12-31")
            ->orderBy('operation_date')
            ->orderBy('created_at')
            ->get()
            ->groupBy('investment_id');

        $posicoes = [];
        $proventos = [];
        $vendas = [];

        foreach ($movimentacoes as $investimentoId => $lista) {
            $ativo = $ativos[$investimentoId];
            $replay = new PositionReplay;
            $fimAnterior = null;

            foreach ($lista as $tx) {
                // Foto de 31/12 do ano anterior: o custo logo antes da primeira movimentação do ano.
                if ($fimAnterior === null && $tx->operation_date->year >= $year) {
                    $fimAnterior = $replay->cost();
                }

                $venda = $replay->apply($tx);

                if ($tx->operation_date->year !== $year) {
                    continue;
                }

                if ($venda !== null) {
                    $vendas[] = $venda + ['date' => $tx->operation_date, 'investment' => $ativo];
                }

                if ($tx->transaction_type->isIncome()) {
                    $proventos[] = [
                        'investment' => $ativo, 'type' => $tx->transaction_type,
                        'gross' => Money::parse($tx->total_amount), 'tax' => Money::parse($tx->other_fees ?? 0), 'net' => Money::parse($tx->net_amount),
                    ];
                }
            }

            $fimAnterior ??= $replay->cost(); // sem movimentação no ano: o custo de antes é o de agora

            if (bccomp($replay->quantity, '0', 6) > 0 || bccomp($fimAnterior, '0', 2) > 0) {
                $posicoes[] = [
                    'investment' => $ativo,
                    'quantity' => $replay->quantity,
                    'average_price' => $replay->averagePrice,
                    'cost' => bccomp($replay->quantity, '0', 6) > 0 ? $replay->cost() : '0.00',
                    'previous_cost' => $fimAnterior,
                ];
            }
        }

        $posicoes = collect($posicoes)->sortBy(fn (array $p) => mb_strtolower($p['investment']->displayName()))->values();
        $incomes = $this->incomes($proventos);

        return [
            'year' => $year,
            'positions' => $posicoes->all(),
            'positions_cost' => Money::sum($posicoes->pluck('cost')),
            'previous_cost' => Money::sum($posicoes->pluck('previous_cost')),
            'incomes' => $incomes['rows'],
            'income_totals' => $incomes['totals'],
            'months' => $this->months($vendas),
            'has_data' => $posicoes->isNotEmpty() || $incomes['rows'] !== [] || $vendas !== [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $proventos
     * @return array{rows: list<array<string, mixed>>, totals: array<string, array<string, string>>}
     */
    private function incomes(array $proventos): array
    {
        $linhas = collect($proventos)
            ->groupBy(fn (array $p) => $p['investment']->id.'|'.$this->incomeKind($p['investment'], $p['type']))
            ->map(function (Collection $grupo): array {
                $primeiro = $grupo->first();
                $tipo = $this->incomeKind($primeiro['investment'], $primeiro['type']);

                return [
                    'investment' => $primeiro['investment'],
                    'kind' => $tipo,
                    'label' => $this->incomeLabel($tipo),
                    'gross' => Money::sum($grupo->pluck('gross')),
                    'tax' => Money::sum($grupo->pluck('tax')),
                    'net' => Money::sum($grupo->pluck('net')),
                ];
            })
            ->sortBy(fn (array $l) => $l['kind'].'|'.mb_strtolower($l['investment']->displayName()))
            ->values();

        $totais = $linhas->groupBy('kind')->map(fn (Collection $g, string $tipo) => [
            'label' => $this->incomeLabel($tipo),
            'gross' => Money::sum($g->pluck('gross')),
            'tax' => Money::sum($g->pluck('tax')),
            'net' => Money::sum($g->pluck('net')),
        ])->all();

        return ['rows' => $linhas->all(), 'totals' => $totais];
    }

    /** A natureza do provento para a declaração: o dividendo de FII é rendimento de fundo, não "lucros e dividendos". */
    private function incomeKind(InvestmentRecord $ativo, TransactionType $tipo): string
    {
        if ($tipo === TransactionType::Dividend && in_array($ativo->asset_class, [AssetClass::Fii, AssetClass::FundoInfra], true)) {
            return 'fii';
        }

        return $tipo->value;
    }

    private function incomeLabel(string $tipo): string
    {
        return match ($tipo) {
            'dividend' => 'Lucros e dividendos',
            'jcp' => 'Juros sobre capital próprio (JCP)',
            'fii' => 'Rendimentos de FII e fundos',
            'amortization' => 'Amortização (reduz o custo, não é rendimento)',
            default => $tipo,
        };
    }

    /**
     * Vendas do ano, mês a mês, agrupadas por classe de ativo (ação, cripto e as demais), com o total vendido e o resultado.
     * Ação e cripto têm isenção mensal quando o total vendido no mês fica no limite; as demais não têm.
     *
     * @param  list<array<string, mixed>>  $vendas
     * @return list<array{month: int, groups: list<array<string, mixed>>, sales: list<array<string, mixed>>}>
     */
    private function months(array $vendas): array
    {
        return collect($vendas)
            ->groupBy(fn (array $v) => (int) $v['date']->month)
            ->sortKeys()
            ->map(function (Collection $doMes, int $mes): array {
                $grupos = $doMes->groupBy(fn (array $v) => $this->saleGroup($v['investment']))->map(function (Collection $g, string $chave): array {
                    $vendido = Money::sum($g->pluck('gross'));
                    $limite = self::EXEMPTION_LIMITS[$chave] ?? null;

                    return [
                        'key' => $chave,
                        'label' => $this->saleGroupLabel($chave),
                        'sold' => $vendido,
                        'result' => Money::sum($g->pluck('result')),
                        'limit' => $limite,
                        'exempt' => $limite !== null && bccomp($vendido, $limite, 2) <= 0,
                    ];
                })->sortKeys()->values()->all();

                return [
                    'month' => $mes,
                    'groups' => $grupos,
                    'sales' => $doMes->sortBy('date')->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function saleGroup(InvestmentRecord $ativo): string
    {
        return match ($ativo->asset_class) {
            AssetClass::Acao => 'acao',
            AssetClass::Cripto => 'cripto',
            AssetClass::Fii, AssetClass::FundoInfra => 'fii',
            default => 'outros',
        };
    }

    private function saleGroupLabel(string $chave): string
    {
        return match ($chave) {
            'acao' => 'Ações',
            'cripto' => 'Criptoativos',
            'fii' => 'FII e fundos de infraestrutura',
            default => 'ETFs e outros ativos',
        };
    }
}
