<?php

namespace App\Services;

use App\Enums\DebtAmortization;
use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Support\Debts\DebtScheduleCalculator;
use App\Support\Debts\DebtSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Visões que ajudam a decidir o que fazer com as dívidas: qual quitar primeiro e como o saldo e as parcelas caem com o tempo.
 *
 * Consórcio fica de fora: antes de contemplado não é dívida, e depois a taxa vem embutida na parcela, sem juros para comparar.
 */
class DebtStrategyService
{
    public const BY_RATE = 'rate';

    public const BY_BALANCE = 'balance';

    /**
     * As dívidas na ordem sugerida para quitar. Por taxa (o método "avalanche") é a que mais economiza juros; por saldo (a "bola
     * de neve") é a que libera parcela mais depressa. Dívida sem taxa conhecida (cartão atrasado, empréstimo sem o valor tomado)
     * vai para o fim e é marcada, porque a ordem dela depende do que a pessoa souber.
     *
     * @return Collection<int, array{debt: Debt, balance: string, installment: ?string, monthly_rate: ?string, annual_percent: ?float, rate_source: ?string}>
     */
    public function ranking(string $modo = self::BY_RATE): Collection
    {
        $linhas = Debt::query()
            ->where('kind', '!=', DebtKind::Consortium->value)
            ->whereIn('status', [DebtStatus::Active->value, DebtStatus::Open->value, DebtStatus::Negotiating->value])
            ->get()
            ->map(function (Debt $d): array {
                [$taxa, $origem] = $this->monthlyRate($d);

                return [
                    'debt' => $d,
                    'balance' => $d->balance(),
                    'installment' => $d->status->isUnscheduled() ? null : $d->installments()->where('status', DebtInstallmentStatus::Open)->value('amount'),
                    'monthly_rate' => $taxa,
                    'annual_percent' => $taxa !== null ? DebtSimulator::annualPercent($taxa) : null,
                    'rate_source' => $origem,
                ];
            })
            ->filter(fn (array $l) => bccomp($l['balance'], '0', 2) > 0);

        return ($modo === self::BY_BALANCE
            ? $linhas->sort(fn (array $a, array $b) => bccomp($a['balance'], $b['balance'], 2))
            : $linhas->sort(function (array $a, array $b): int {
                // Maior taxa primeiro; sem taxa, no fim; empate pelo menor saldo.
                return match (true) {
                    $a['monthly_rate'] === null && $b['monthly_rate'] === null => bccomp($a['balance'], $b['balance'], 2),
                    $a['monthly_rate'] === null => 1,
                    $b['monthly_rate'] === null => -1,
                    default => bccomp($b['monthly_rate'], $a['monthly_rate'], 8) ?: bccomp($a['balance'], $b['balance'], 2),
                };
            }))->values();
    }

    /**
     * A taxa mensal da dívida e de onde ela vem: do contrato (SAC/Price) ou calculada pelas parcelas e o valor tomado.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function monthlyRate(Debt $d): array
    {
        if ($d->status->isUnscheduled()) {
            return [null, null];
        }

        if ($d->amortization !== DebtAmortization::Fixed && $d->rate !== null && $d->rate_basis !== null) {
            return [DebtScheduleCalculator::monthlyRate((string) $d->rate, $d->rate_basis), 'contrato'];
        }

        if ($d->amortization === DebtAmortization::Fixed && $d->principal !== null && bccomp((string) $d->principal, '0', 2) > 0) {
            $taxa = DebtSimulator::impliedMonthlyRate((string) $d->principal, $d->installments()->pluck('amount')->map(fn ($v) => (string) $v)->all());

            return $taxa === null ? [null, null] : [$taxa, 'parcelas'];
        }

        return [null, null];
    }

    /**
     * Mês a mês: o que se paga de parcela e quanto ainda se deve ao fim do mês, só das dívidas com cronograma. O atrasado cai no
     * mês atual. As somas são em SQL (regra 3); aqui só se junta o resultado por mês.
     *
     * @return array{labels: list<string>, months: list<string>, installments: list<float>, balance: list<float>, total_balance: string}
     */
    public function projection(int $limite = 120): array
    {
        $hoje = CarbonImmutable::now()->startOfMonth();

        $dividas = Debt::query()
            ->where('status', DebtStatus::Active->value)
            ->get()
            ->filter(fn (Debt $d) => ! $d->isSavingForCredit())
            ->keyBy('id');

        if ($dividas->isEmpty()) {
            return ['labels' => [], 'months' => [], 'installments' => [], 'balance' => [], 'total_balance' => '0.00'];
        }

        $linhas = DebtInstallment::query()
            ->whereIn('debt_id', $dividas->keys())
            ->where('status', DebtInstallmentStatus::Open->value)
            ->select('debt_id', DB::raw("DATE_FORMAT(due_date, '%Y-%m') as ym"), DB::raw('SUM(amount) as parcelas'), DB::raw('SUM(principal_amount) as principal'))
            ->groupBy('debt_id', 'ym')
            ->get();

        $porMes = [];
        $saldoTotal = '0.00';

        foreach ($linhas as $l) {
            $ym = $l->ym < $hoje->format('Y-m') ? $hoje->format('Y-m') : $l->ym;
            $baixa = $dividas[$l->debt_id]->amortization === DebtAmortization::Fixed ? (string) $l->parcelas : (string) $l->principal;

            $porMes[$ym]['parcelas'] = bcadd($porMes[$ym]['parcelas'] ?? '0', (string) $l->parcelas, 2);
            $porMes[$ym]['baixa'] = bcadd($porMes[$ym]['baixa'] ?? '0', $baixa, 2);
            $saldoTotal = bcadd($saldoTotal, $baixa, 2);
        }

        if ($porMes === []) {
            return ['labels' => [], 'months' => [], 'installments' => [], 'balance' => [], 'total_balance' => '0.00'];
        }

        ksort($porMes);
        $ultimo = min(array_key_last($porMes), $hoje->addMonths($limite - 1)->format('Y-m'));
        $saldo = $saldoTotal;
        $meses = $labels = $parcelas = $saldos = [];

        for ($m = $hoje; $m->format('Y-m') <= $ultimo; $m = $m->addMonth()) {
            $ym = $m->format('Y-m');
            $saldo = bcsub($saldo, $porMes[$ym]['baixa'] ?? '0', 2);

            $meses[] = $ym;
            $labels[] = $m->translatedFormat('M/y');
            $parcelas[] = (float) ($porMes[$ym]['parcelas'] ?? 0);
            $saldos[] = (float) max($saldo, '0');
        }

        return ['labels' => $labels, 'months' => $meses, 'installments' => $parcelas, 'balance' => $saldos, 'total_balance' => $saldoTotal];
    }
}
