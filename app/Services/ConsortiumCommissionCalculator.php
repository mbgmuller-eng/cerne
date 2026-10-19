<?php

namespace App\Services;

use App\Enums\IncomeSource;
use App\Models\CommissionRule;
use App\Models\Debt;
use App\Models\DebtCommission;
use App\Support\ConsortiumSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Conta da comissão de um consórcio, sem gravar nada: percentual da carta, dividido pelas parcelas combinadas.
 *
 * - Vendeu (`sold`): vale o percentual e o cronograma da regra da administradora, a não ser que o vínculo traga os seus.
 * - Terceiro vendeu (`third_party`): só vale o percentual que o profissional informou no vínculo.
 *
 * Cada parte cai na data de vencimento da parcela (mais o prazo de pagamento da regra). Só do mês atual em diante, como nas
 * outras origens: o que já passou o profissional lança à mão. A carta é a de hoje, então um reajuste muda a previsão.
 */
class ConsortiumCommissionCalculator
{
    /** Quanto à frente se enxerga. */
    public const HORIZON_MONTHS = 12;

    /**
     * @param  Collection<int, \App\Models\DebtInstallment>  $parcelas  as parcelas do consórcio, pelo número
     * @return list<array{key: string, source: IncomeSource, due_on: string, amount: string, description: string}>
     */
    public function entries(Debt $debt, DebtCommission $vinculo, ?CommissionRule $regra, Collection $parcelas, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $percentual = $this->percent($vinculo, $regra);
        $cronograma = $vinculo->schedule_override ?: $regra?->installment_schedule;
        $carta = (string) $debt->credit_amount;

        if ($percentual === null || bccomp($percentual, '0', 4) <= 0 || blank($cronograma) || bccomp($carta, '0', 2) <= 0) {
            return [];
        }

        $total = bcadd(bcdiv(bcmul($carta, $percentual, 6), '100', 6), '0.005', 2);
        $partes = ConsortiumSchedule::split($total, $cronograma);
        $primeiroMes = $hoje->startOfMonth();
        $limite = $hoje->addMonthsNoOverflow(self::HORIZON_MONTHS)->endOfMonth()->startOfDay();
        $atraso = (int) ($regra?->payout_delay_days ?? 0);
        $itens = [];

        foreach ($partes as $numero => $valor) {
            $parcela = $parcelas->get($numero);

            if ($parcela === null || bccomp($valor, '0', 2) <= 0) {
                continue;
            }

            $vencimento = CarbonImmutable::parse($parcela->due_date)->addDays($atraso);

            if ($vencimento->lt($primeiroMes) || $vencimento->gt($limite)) {
                continue;
            }

            $itens[] = [
                'key' => "consortium:{$debt->id}:{$numero}",
                'source' => IncomeSource::ConsortiumCommission,
                'due_on' => $vencimento->toDateString(),
                'amount' => $valor,
                'description' => 'Comissão de consórcio'.(filled($debt->creditor) ? " {$debt->creditor}" : '').", parcela {$numero}",
            ];
        }

        return $itens;
    }

    /** Percentual da carta que vale para este vínculo, ou nulo se não há como saber. */
    public function percent(DebtCommission $vinculo, ?CommissionRule $regra): ?string
    {
        if ($vinculo->percent_override !== null) {
            return (string) $vinculo->percent_override;
        }

        return $vinculo->role === DebtCommission::ROLE_SOLD && $regra?->credit_percent !== null ? (string) $regra->credit_percent : null;
    }
}
