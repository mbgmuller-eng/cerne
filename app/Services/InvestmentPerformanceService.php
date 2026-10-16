<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\InvestmentPortfolioMonth;
use App\Models\InvestmentRecord;
use App\Models\InvestmentTransaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dados dos gráficos de desempenho dos investimentos: evolução do patrimônio, rentabilidade contra o CDI, ganho em reais,
 * composição da carteira e proventos recebidos.
 *
 * Patrimônio, rentabilidade e ganho vêm da carteira mês a mês que a corretora informa (InvestmentPortfolioMonth); a
 * composição vem dos ativos de hoje; os proventos, das movimentações, pela data do recebimento. A rentabilidade acumulada é
 * composta (juros sobre juros) com bcmath, e o CDI é composto da mesma forma, só nos meses em que os dois existem, para a
 * comparação ser justa.
 */
class InvestmentPerformanceService
{
    private const MONTHS = [1 => 'jan', 2 => 'fev', 3 => 'mar', 4 => 'abr', 5 => 'mai', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'set', 10 => 'out', 11 => 'nov', 12 => 'dez'];

    /**
     * @param  int  $months  janela em meses, contada do último mês com dado; 0 = todo o histórico
     * @return array<string, mixed>
     */
    public function forPeriod(?string $memberId, int $months): array
    {
        $carteira = $this->portfolio($memberId, $months);
        $allocation = $this->allocation($memberId);
        $incomes = $this->incomes($memberId, $months);

        return [
            'has_data' => $carteira['months'] !== [] || $allocation['slices'] !== [] || $incomes['total'] !== '0.00',
            'portfolio' => $carteira,
            'allocation' => $allocation,
            'incomes' => $incomes,
        ];
    }

    /** @return array<string, mixed> */
    private function portfolio(?string $memberId, int $months): array
    {
        $linhas = InvestmentPortfolioMonth::query()
            ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->orderBy('year')->orderBy('month')
            ->get()
            ->groupBy(fn (InvestmentPortfolioMonth $m) => sprintf('%04d-%02d', $m->year, $m->month))
            ->map(fn (Collection $grupo, string $chave) => $this->mergeMonth($grupo, $chave))
            ->values();

        // O relatório da corretora tem lacunas (meses sem informação). O eixo é o calendário: mês sem dado vira buraco no
        // gráfico, em vez de emendar meses distantes como se fossem vizinhos.
        if ($linhas->isNotEmpty()) {
            $ultimo = CarbonImmutable::createFromDate($linhas->last()['year'], $linhas->last()['month'], 1);
            $primeiro = CarbonImmutable::createFromDate($linhas->first()['year'], $linhas->first()['month'], 1);
            $inicio = $months > 0 ? max($primeiro, $ultimo->subMonths($months - 1)) : $primeiro;
            $porMes = $linhas->keyBy(fn (array $m) => sprintf('%04d-%02d', $m['year'], $m['month']));
            $calendario = [];

            for ($cursor = $inicio; $cursor <= $ultimo; $cursor = $cursor->addMonth()) {
                $calendario[] = $porMes->get($cursor->format('Y-m'))
                    ?? ['year' => $cursor->year, 'month' => $cursor->month, 'closing' => null, 'gain' => null, 'return' => null, 'cdi' => null];
            }

            $linhas = collect($calendario);
        }
        $rotulos = $linhas->map(fn (array $m) => $this->label($m['year'], $m['month']))->all();

        // Patrimônio: só os meses com saldo (o relatório da corretora traz os últimos 12).
        $comSaldo = $linhas->filter(fn (array $m) => $m['closing'] !== null)->values();
        $patrimonio = [
            'labels' => $comSaldo->map(fn (array $m) => $this->label($m['year'], $m['month']))->all(),
            'values' => $comSaldo->map(fn (array $m) => (float) $m['closing'])->all(),
            'first' => $comSaldo->first()['closing'] ?? null,
            'last' => $comSaldo->last()['closing'] ?? null,
            'last_label' => $comSaldo->isEmpty() ? null : $this->label($comSaldo->last()['year'], $comSaldo->last()['month']),
        ];

        // Rentabilidade composta, só nos meses em que a carteira e o CDI existem.
        $acumuladoCarteira = '1';
        $acumuladoCdi = '1';
        $cumCarteira = [];
        $cumCdi = [];
        $mensalCarteira = [];
        $mensalCdi = [];
        $acima = 0;
        $comparados = 0;
        $melhor = null;
        $pior = null;

        // O acumulado só faz sentido sem buracos: vale a sequência contínua mais recente em que a carteira e o CDI existem.
        $inicioSequencia = $linhas->count();

        for ($j = $linhas->count() - 1; $j >= 0; $j--) {
            if ($linhas[$j]['return'] === null || $linhas[$j]['cdi'] === null) {
                break;
            }

            $inicioSequencia = $j;
        }

        foreach ($linhas as $i => $m) {
            $r = $m['return'];
            $c = $m['cdi'];
            $mensalCarteira[$i] = $r === null ? null : (float) $r;
            $mensalCdi[$i] = $c === null ? null : (float) $c;

            if ($i < $inicioSequencia) {
                $cumCarteira[$i] = null;
                $cumCdi[$i] = null;

                continue;
            }

            $acumuladoCarteira = bcmul($acumuladoCarteira, bcadd('1', bcdiv($r, '100', 10), 10), 10);
            $acumuladoCdi = bcmul($acumuladoCdi, bcadd('1', bcdiv($c, '100', 10), 10), 10);
            $cumCarteira[$i] = (float) bcmul(bcsub($acumuladoCarteira, '1', 10), '100', 4);
            $cumCdi[$i] = (float) bcmul(bcsub($acumuladoCdi, '1', 10), '100', 4);
            $comparados++;

            if (bccomp($r, $c, 4) > 0) {
                $acima++;
            }

            $item = ['label' => $rotulos[$i], 'value' => $r];
            $melhor = $melhor === null || bccomp($r, $melhor['value'], 4) > 0 ? $item : $melhor;
            $pior = $pior === null || bccomp($r, $pior['value'], 4) < 0 ? $item : $pior;
        }

        $totalCarteira = $comparados > 0 ? number_format((float) bcmul(bcsub($acumuladoCarteira, '1', 10), '100', 6), 2, '.', '') : null;
        $totalCdi = $comparados > 0 ? number_format((float) bcmul(bcsub($acumuladoCdi, '1', 10), '100', 6), 2, '.', '') : null;

        return [
            'months' => $rotulos,
            'patrimony' => $patrimonio,
            'monthly_return' => $mensalCarteira,
            'monthly_cdi' => $mensalCdi,
            'cumulative_return' => array_values($cumCarteira),
            'cumulative_cdi' => array_values($cumCdi),
            // Posição em que o acumulado começa: o gráfico dele corta o que vem antes, que não tem linha.
            'sequence_offset' => $comparados > 0 ? $inicioSequencia : 0,
            'gain' => $linhas->map(fn (array $m) => $m['gain'] === null ? null : (float) $m['gain'])->all(),
            'summary' => [
                'return' => $totalCarteira,
                'cdi' => $totalCdi,
                // Quanto do CDI a carteira rendeu no período: 100 = igual ao CDI.
                'percent_of_cdi' => $totalCdi !== null && bccomp($totalCdi, '0', 2) > 0 ? (string) round((float) bcmul(bcdiv($totalCarteira, $totalCdi, 6), '100', 4)) : null,
                'months_above_cdi' => $acima,
                'months_compared' => $comparados,
                // Início da sequência contínua usada no acumulado; só vem preenchido se ela é mais curta que a janela pedida.
                'sequence_start' => $comparados > 0 && $inicioSequencia > 0 ? $rotulos[$inicioSequencia] : null,
                'best' => $melhor,
                'worst' => $pior,
                'gain_total' => $linhas->contains(fn (array $m) => $m['gain'] !== null) ? Money::sum($linhas->pluck('gain')->filter(fn ($g) => $g !== null)) : null,
            ],
        ];
    }

    /**
     * Um mês pode ter mais de uma linha (outra corretora ou outra pessoa do perfil): os saldos somam e as rentabilidades
     * são ponderadas pelo patrimônio de abertura (sem abertura, peso igual).
     *
     * @param  Collection<int, InvestmentPortfolioMonth>  $grupo
     * @return array<string, mixed>
     */
    private function mergeMonth(Collection $grupo, string $chave): array
    {
        [$ano, $mes] = array_map('intval', explode('-', $chave));

        if ($grupo->count() === 1) {
            $m = $grupo->first();

            return ['year' => $ano, 'month' => $mes, 'closing' => $m->closing_balance, 'gain' => $m->gain, 'return' => $m->return_percent, 'cdi' => $m->cdi_percent];
        }

        $soma = fn (string $campo) => $grupo->contains(fn ($m) => $m->{$campo} !== null) ? Money::sum($grupo->pluck($campo)->filter(fn ($v) => $v !== null)) : null;
        $ponderar = function (string $campo) use ($grupo): ?string {
            $itens = $grupo->filter(fn ($m) => $m->{$campo} !== null);

            if ($itens->isEmpty()) {
                return null;
            }

            $pesoTotal = '0';
            $acumulado = '0';

            foreach ($itens as $m) {
                $peso = $m->opening_balance !== null && bccomp((string) $m->opening_balance, '0', 2) > 0 ? (string) $m->opening_balance : '1';
                $pesoTotal = bcadd($pesoTotal, $peso, 2);
                $acumulado = bcadd($acumulado, bcmul((string) $m->{$campo}, $peso, 6), 6);
            }

            return bcdiv($acumulado, $pesoTotal, 4);
        };

        return ['year' => $ano, 'month' => $mes, 'closing' => $soma('closing_balance'), 'gain' => $soma('gain'), 'return' => $ponderar('return_percent'), 'cdi' => $ponderar('cdi_percent')];
    }

    /** @return array{slices: list<array<string, mixed>>, total: string} */
    private function allocation(?string $memberId): array
    {
        $ativos = InvestmentRecord::query()
            ->active()
            ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->where('current_amount', '>', 0)
            ->get();

        $total = Money::sum($ativos->pluck('current_amount'));

        if (bccomp($total, '0', 2) <= 0) {
            return ['slices' => [], 'total' => '0.00'];
        }

        $fatias = $ativos
            ->groupBy(fn (InvestmentRecord $a) => $a->displayGroup()->value)
            ->map(function (Collection $grupo) use ($total): array {
                $valor = Money::sum($grupo->pluck('current_amount'));
                $grupoExibicao = $grupo->first()->displayGroup();

                return [
                    'label' => $grupoExibicao->label(),
                    'color' => $grupoExibicao->color(),
                    'value' => $valor,
                    'pct' => round((float) bcmul(bcdiv($valor, $total, 6), '100', 4), 1),
                    'assets' => $grupo->sortByDesc(fn (InvestmentRecord $a) => (float) $a->current_amount)->map(fn (InvestmentRecord $a) => ['name' => $a->displayName(), 'value' => (string) $a->current_amount])->values()->all(),
                ];
            })
            ->sortByDesc(fn (array $f) => (float) $f['value'])
            ->values()
            ->all();

        return ['slices' => $fatias, 'total' => $total];
    }

    /** @return array<string, mixed> */
    private function incomes(?string $memberId, int $months): array
    {
        $fim = CarbonImmutable::today()->endOfMonth();
        $janela = $months > 0 ? $months : 36;
        $inicio = $fim->startOfMonth()->subMonths($janela - 1);

        $linhas = InvestmentTransaction::query()
            ->whereIn('transaction_type', [TransactionType::Dividend->value, TransactionType::Jcp->value])
            ->whereDate('operation_date', '>=', $inicio->toDateString())
            ->whereDate('operation_date', '<=', $fim->toDateString())
            ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->selectRaw('YEAR(operation_date) as ano, MONTH(operation_date) as mes, transaction_type as tipo, SUM(net_amount) as total')
            ->groupBy('ano', 'mes', 'tipo')
            ->get();

        // Com "tudo", a janela começa no primeiro mês que de fato recebeu provento.
        if ($months === 0 && $linhas->isNotEmpty()) {
            $primeiro = $linhas->map(fn ($l) => sprintf('%04d-%02d', $l->ano, $l->mes))->min();
            $inicio = CarbonImmutable::createFromFormat('Y-m-d', $primeiro.'-01')->startOfMonth();
        }

        $rotulos = [];
        $dividendos = [];
        $jcp = [];
        $cursor = $inicio;

        while ($cursor <= $fim) {
            $rotulos[] = $this->label($cursor->year, $cursor->month);
            $doMes = $linhas->where('ano', $cursor->year)->where('mes', $cursor->month);
            $dividendos[] = (float) ($doMes->firstWhere('tipo', TransactionType::Dividend->value)->total ?? 0);
            $jcp[] = (float) ($doMes->firstWhere('tipo', TransactionType::Jcp->value)->total ?? 0);
            $cursor = $cursor->addMonth();
        }

        $total = Money::sum($linhas->pluck('total'));
        $mesesComProvento = $linhas->map(fn ($l) => $l->ano.'-'.$l->mes)->unique()->count();

        $porAtivo = InvestmentTransaction::query()
            ->whereIn('transaction_type', [TransactionType::Dividend->value, TransactionType::Jcp->value])
            ->whereDate('operation_date', '>=', $inicio->toDateString())
            ->whereDate('operation_date', '<=', $fim->toDateString())
            ->when($memberId, fn ($q) => $q->where('member_id', $memberId))
            ->selectRaw('investment_id, SUM(net_amount) as total')
            ->groupBy('investment_id')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $nomes = InvestmentRecord::query()->whereIn('id', $porAtivo->pluck('investment_id'))->get()->keyBy('id');

        return [
            'months' => $rotulos,
            'dividends' => $dividendos,
            'jcp' => $jcp,
            'maximum' => max(array_map(fn ($d, $j) => $d + $j, $dividendos ?: [0.0], $jcp ?: [0.0])),
            'total' => $total,
            'monthly_average' => $janela > 0 && count($rotulos) > 0 ? bcdiv($total, (string) count($rotulos), 2) : '0.00',
            'months_with_income' => $mesesComProvento,
            'by_asset' => $porAtivo->map(fn ($l) => ['name' => $nomes[$l->investment_id]?->displayName() ?? 'Ativo removido', 'total' => Money::parse($l->total)])->all(),
        ];
    }

    private function label(int $ano, int $mes): string
    {
        return self::MONTHS[$mes].'/'.substr((string) $ano, 2);
    }
}
