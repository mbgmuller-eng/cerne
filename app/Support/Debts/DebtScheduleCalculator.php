<?php

namespace App\Support\Debts;

use App\Enums\DebtAmortization;
use App\Enums\DebtRateBasis;
use InvalidArgumentException;

/**
 * Cronograma de parcelas de um financiamento ou empréstimo a partir do contrato (valor, taxa, prazo, sistema).
 *
 * Dinheiro em bcmath, nunca float (regra 3 do CLAUDE.md): cada parcela é arredondada ao centavo e a última absorve a sobra, de
 * modo que a soma das amortizações fecha exatamente no valor financiado. A taxa efetiva anual é a única conversão feita em
 * ponto flutuante (é uma taxa, não dinheiro) e só vira a taxa mensal que entra nas contas.
 */
final class DebtScheduleCalculator
{
    private const SCALE = 12;

    /** A taxa mensal, como fração (1% ao mês = "0.010000000000"). */
    public static function monthlyRate(string $percent, DebtRateBasis $base): string
    {
        $anual = bcdiv($percent, '100', self::SCALE);

        return match ($base) {
            DebtRateBasis::Monthly => $anual,
            DebtRateBasis::AnnualNominal => bcdiv($anual, '12', self::SCALE),
            DebtRateBasis::AnnualEffective => number_format(max(pow(1 + (float) $anual, 1 / 12) - 1, 0), self::SCALE, '.', ''),
        };
    }

    /**
     * @param  string  $monthlyRate  fração mensal (ver monthlyRate())
     * @return list<array{principal: string, interest: string, fees: string, amount: string}>
     */
    public static function schedule(DebtAmortization $sistema, string $principal, string $monthlyRate, int $term, string $fees = '0.00'): array
    {
        if ($sistema === DebtAmortization::Fixed) {
            throw new InvalidArgumentException('Parcelas informadas não têm cronograma calculado.');
        }

        if ($term < 1 || bccomp($principal, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o valor financiado e o prazo.');
        }

        $saldo = bcadd($principal, '0', 2);
        $parcelaPrice = $sistema === DebtAmortization::Price ? self::pmt($principal, $monthlyRate, $term) : null;
        $linhas = [];

        for ($k = 1; $k <= $term; $k++) {
            $juros = self::round2(bcmul($saldo, $monthlyRate, self::SCALE));
            $ultima = $k === $term;

            $amortizacao = match (true) {
                $ultima => $saldo,
                $sistema === DebtAmortization::Sac => self::round2(bcdiv($principal, (string) $term, self::SCALE)),
                default => bcsub($parcelaPrice, $juros, 2),
            };

            // Price com taxa muito baixa pode pedir amortização maior que o saldo antes da última parcela: nunca passa do saldo.
            if (bccomp($amortizacao, $saldo, 2) > 0) {
                $amortizacao = $saldo;
            }

            $linhas[] = [
                'principal' => $amortizacao,
                'interest' => $juros,
                'fees' => bcadd($fees, '0', 2),
                'amount' => bcadd(bcadd($amortizacao, $juros, 2), $fees, 2),
            ];

            $saldo = bcsub($saldo, $amortizacao, 2);
        }

        return $linhas;
    }

    /** Parcela fixa da tabela Price: P·i / (1 − (1+i)^−n). Sem juros, é só P / n. */
    private static function pmt(string $principal, string $i, int $n): string
    {
        if (bccomp($i, '0', self::SCALE) === 0) {
            return self::round2(bcdiv($principal, (string) $n, self::SCALE));
        }

        $fator = '1';
        $base = bcadd('1', $i, self::SCALE);

        for ($k = 0; $k < $n; $k++) {
            $fator = bcmul($fator, $base, self::SCALE);
        }

        // P·i·fator / (fator − 1)
        return self::round2(bcdiv(bcmul(bcmul($principal, $i, self::SCALE), $fator, self::SCALE), bcsub($fator, '1', self::SCALE), self::SCALE));
    }

    private static function round2(string $valor): string
    {
        return bcadd($valor, '0.005', 2);
    }
}
