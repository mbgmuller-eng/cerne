<?php

namespace App\Services;

use App\Enums\CommissionType;
use App\Enums\IncomeSource;
use App\Enums\PaymentFrequency;
use App\Models\CommissionRule;
use App\Models\InsurancePolicy;
use Carbon\CarbonImmutable;

/**
 * Conta da comissão de uma apólice, sem tocar no banco: dado a apólice e a regra da seguradora, quais recebimentos
 * esperar nos próximos meses.
 *
 * - Comissão: percentual sobre cada parcela que o cliente paga, no percentual do ano da apólice (1º ano ou renovação).
 * - Agenciamento: uma vez só, no início de uma apólice nova (percentual do prêmio do 1º ano ou valor fixo).
 *
 * Só olha do mês atual em diante, como o honorário: o que já passou o profissional lança à mão. Dinheiro em bcmath,
 * com meio centavo para cima (a comissão é dinheiro e bcmul/bcdiv truncam).
 */
class InsuranceCommissionCalculator
{
    /** Quanto à frente se enxerga. */
    public const HORIZON_MONTHS = 12;

    /**
     * @return list<array{key: string, source: IncomeSource, due_on: string, amount: string, description: string}>
     */
    public function entries(InsurancePolicy $policy, CommissionRule $rule, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $primeiroMes = $hoje->startOfMonth();
        $limite = $hoje->addMonthsNoOverflow(self::HORIZON_MONTHS)->endOfMonth()->startOfDay();
        $frequencia = $policy->payment_frequency;
        $parcela = $this->installment($policy, $frequencia);

        if (bccomp($parcela, '0', 2) <= 0 || $policy->start_date === null) {
            return [];
        }

        $inicio = CarbonImmutable::parse($policy->start_date);
        $fimDaApolice = $policy->expiry_date !== null && ! $policy->auto_renews ? CarbonImmutable::parse($policy->expiry_date) : null;
        $ciclo = intdiv(12, $frequencia->chargesPerYear());
        $atraso = (int) $rule->payout_delay_days;
        $nome = $this->label($policy);
        $itens = [];

        for ($k = 0; ; $k++) {
            $vencimento = $inicio->addMonthsNoOverflow($k * $ciclo);

            if ($vencimento->gt($limite) || ($fimDaApolice !== null && $vencimento->gt($fimDaApolice))) {
                break;
            }

            if ($vencimento->lt($primeiroMes)) {
                continue;
            }

            $ano = intdiv($k * $ciclo, 12) + 1;
            $percentual = $rule->percentForYear($ano);

            if ($percentual === null) {
                continue;
            }

            $valor = $this->percentOf($parcela, (string) $percentual);

            if (bccomp($valor, '0', 2) <= 0) {
                continue;
            }

            $itens[] = [
                'key' => "policy:{$policy->id}:commission:{$vencimento->toDateString()}",
                'source' => IncomeSource::InsuranceCommission,
                'due_on' => $vencimento->addDays($atraso)->toDateString(),
                'amount' => $valor,
                'description' => "Comissão {$nome} ({$ano}º ano)",
            ];
        }

        // Agenciamento: só de apólice que começa de agora em diante; a antiga não gera fechamento retroativo.
        if ($rule->hasAgency() && $inicio->gte($primeiroMes) && $inicio->lte($limite)) {
            $valor = $rule->agency_type === CommissionType::Amount
                ? number_format((float) $rule->agency_amount, 2, '.', '')
                : $this->percentOf($this->firstYearPremium($policy, $frequencia, $parcela), (string) $rule->agency_percent);

            if (bccomp($valor, '0', 2) > 0) {
                $itens[] = [
                    'key' => "policy:{$policy->id}:agency",
                    'source' => IncomeSource::InsuranceAgency,
                    'due_on' => $inicio->addDays($atraso)->toDateString(),
                    'amount' => $valor,
                    'description' => "Agenciamento {$nome}",
                ];
            }
        }

        return $itens;
    }

    /**
     * Valor de cada cobrança. `monthly_premium` guarda a parcela na periodicidade da apólice (mensal, trimestral ou
     * anual); na anual, o prêmio anual informado manda.
     */
    private function installment(InsurancePolicy $policy, PaymentFrequency $frequencia): string
    {
        if ($frequencia === PaymentFrequency::Annual && $policy->annual_premium !== null) {
            return (string) $policy->annual_premium;
        }

        return (string) $policy->monthly_premium;
    }

    private function firstYearPremium(InsurancePolicy $policy, PaymentFrequency $frequencia, string $parcela): string
    {
        if ($policy->annual_premium !== null) {
            return (string) $policy->annual_premium;
        }

        return bcmul($parcela, (string) $frequencia->chargesPerYear(), 2);
    }

    private function percentOf(string $valor, string $percentual): string
    {
        return bcadd(bcdiv(bcmul($valor, $percentual, 6), '100', 6), '0.005', 2);
    }

    private function label(InsurancePolicy $policy): string
    {
        $numero = filled($policy->policy_number) ? ", apólice {$policy->policy_number}" : '';

        return trim("{$policy->insurer_name}{$numero}");
    }
}
