<?php

namespace App\Services;

use App\Enums\DebtInstallmentStatus;
use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Models\Debt;
use App\Models\DebtInstallment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Apoio à seção "Dívidas e ônus reais" da declaração do imposto de renda: quanto se devia em 31/12 do ano-base e do ano anterior,
 * por dívida, com o credor e o CPF ou CNPJ dele. É conferência com o que está no Cerne, não a declaração.
 *
 * O saldo em uma data é o que o cronograma ainda não tinha quitado nela: parcelas em aberto, mais as pagas depois da data. O
 * valor vem das amortizações (SAC e Price) ou do total das parcelas, quando o contrato só tem parcelas informadas. Somas em SQL.
 */
class DebtTaxReportService
{
    /**
     * @return array{year: int, rows: list<array<string, mixed>>, total_previous: string, total_current: string, unscheduled: list<array<string, mixed>>, has_data: bool}
     */
    public function forYear(int $ano, ?string $memberId = null): array
    {
        $fim = CarbonImmutable::create($ano, 12, 31)->endOfDay();
        $fimAnterior = CarbonImmutable::create($ano - 1, 12, 31)->endOfDay();

        $dividas = Debt::query()
            ->with('member')
            ->whereIn('kind', [DebtKind::Financing->value, DebtKind::Loan->value, DebtKind::Consortium->value])
            ->when($memberId !== null, fn ($q) => $q->where(fn ($w) => $w->where('member_id', $memberId)->orWhereNull('member_id')))
            ->orderBy('name')
            ->get()
            // Consórcio só é dívida depois de contemplado.
            ->filter(fn (Debt $d) => ! $d->isConsortium() || $d->isContemplated())
            ->values();

        $atual = $this->balancesAt($dividas, $fim);
        $anterior = $this->balancesAt($dividas, $fimAnterior);
        $linhas = [];

        foreach ($dividas as $d) {
            $a = $atual[$d->id] ?? '0.00';
            $p = $anterior[$d->id] ?? '0.00';

            if (bccomp($a, '0', 2) <= 0 && bccomp($p, '0', 2) <= 0) {
                continue;
            }

            $linhas[] = [
                'debt' => $d,
                'creditor' => $d->creditor,
                'document' => $d->creditor_document,
                'contracted_on' => $this->contractDate($d),
                'contract_estimated' => $d->contracted_on === null,
                'current' => $a,
                'previous' => $p,
                'joint' => $d->member_id === null,
            ];
        }

        $semCronograma = Debt::query()
            ->whereIn('status', [DebtStatus::Open->value, DebtStatus::Negotiating->value])
            ->when($memberId !== null, fn ($q) => $q->where(fn ($w) => $w->where('member_id', $memberId)->orWhereNull('member_id')))
            ->orderBy('name')
            ->get()
            ->map(fn (Debt $d) => ['debt' => $d, 'balance' => $d->balance(), 'updated_on' => $d->balance_updated_on])
            ->all();

        return [
            'year' => $ano,
            'rows' => $linhas,
            'total_previous' => Money::sum(array_column($linhas, 'previous')),
            'total_current' => Money::sum(array_column($linhas, 'current')),
            'unscheduled' => $semCronograma,
            'has_data' => $linhas !== [] || $semCronograma !== [],
        ];
    }

    /** O dia em que o contrato começou: o informado, ou (estimativa) um mês antes da primeira parcela. Consórcio, a contemplação. */
    private function contractDate(Debt $d): ?CarbonImmutable
    {
        if ($d->isConsortium()) {
            return $d->contemplated_on ? CarbonImmutable::instance($d->contemplated_on) : null;
        }

        if ($d->contracted_on !== null) {
            return CarbonImmutable::instance($d->contracted_on);
        }

        return $d->first_due_date ? CarbonImmutable::instance($d->first_due_date)->subMonthNoOverflow() : null;
    }

    /**
     * @param  Collection<int, Debt>  $dividas
     * @return array<string, string> debt_id => saldo na data
     */
    private function balancesAt(Collection $dividas, CarbonImmutable $data): array
    {
        $existentes = $dividas->filter(function (Debt $d) use ($data): bool {
            $inicio = $this->contractDate($d);

            return $inicio !== null && $inicio->lessThanOrEqualTo($data);
        });

        if ($existentes->isEmpty()) {
            return [];
        }

        $somas = DebtInstallment::query()
            ->whereIn('debt_id', $existentes->pluck('id'))
            ->where('status', '!=', DebtInstallmentStatus::Diluted->value)
            ->where(fn ($q) => $q->whereNull('paid_on')->orWhere('paid_on', '>', $data->toDateString()))
            ->select('debt_id', DB::raw('SUM(amount) as total'), DB::raw('SUM(principal_amount) as principal'))
            ->groupBy('debt_id')
            ->get()
            ->keyBy('debt_id');

        return $existentes->mapWithKeys(function (Debt $d) use ($somas): array {
            $l = $somas->get($d->id);
            $valor = $l === null ? '0.00' : ($d->amortization->value === 'fixed' ? (string) $l->total : (string) $l->principal);

            return [$d->id => Money::parse($valor)];
        })->all();
    }
}
