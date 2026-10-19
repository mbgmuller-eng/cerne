<?php

namespace App\Support\Debts;

use App\Enums\DebtAmortization;
use InvalidArgumentException;

/**
 * Simulações sobre o que ainda falta pagar de um financiamento ou empréstimo com taxa conhecida (SAC ou Price): antecipar
 * parte do saldo e trocar de banco (portabilidade). São estimativas do contrato: usam a taxa cadastrada e ignoram o que o banco
 * cobra à parte (IOF, tarifas, correção por TR), que a pessoa pode informar como custo da operação.
 *
 * Dinheiro em bcmath (regra 3); só o número de parcelas de uma antecipação "reduzindo o prazo" passa por logaritmo em ponto
 * flutuante, e vira um inteiro arredondado para cima.
 */
final class DebtSimulator
{
    /**
     * Antecipar `$extra` agora, com duas escolhas: manter a parcela e encurtar o prazo ("term") ou manter o prazo e baixar a
     * parcela ("installment").
     *
     * @param  string  $monthlyRate  fração mensal (ver DebtScheduleCalculator::monthlyRate)
     * @param  string  $firstAmortization  amortização da próxima parcela (SAC mantém a amortização ao encurtar o prazo)
     * @param  string  $firstAmount  valor da próxima parcela, já com taxas fixas (Price mantém esse valor ao encurtar o prazo)
     * @return array{paid_off: bool, term: int, first_installment: string, total_to_pay: string, interest: string, balance_after: string}
     */
    public static function prepay(DebtAmortization $sistema, string $balance, string $monthlyRate, string $fees, int $remainingTerm, string $firstAmortization, string $firstAmount, string $extra, string $mode): array
    {
        self::requireRate($sistema);

        if (bccomp($extra, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o valor a antecipar.');
        }

        $depois = bcsub($balance, $extra, 2);

        if (bccomp($depois, '0', 2) <= 0) {
            return ['paid_off' => true, 'term' => 0, 'first_installment' => '0.00', 'total_to_pay' => '0.00', 'interest' => '0.00', 'balance_after' => '0.00'];
        }

        $prazo = $remainingTerm;

        if ($mode === 'term') {
            $prazo = match ($sistema) {
                DebtAmortization::Sac => max(1, (int) ceil((float) bcdiv($depois, $firstAmortization, 8))),
                default => self::priceTermFor($depois, $monthlyRate, bcsub($firstAmount, $fees, 2), $remainingTerm),
            };
        }

        $linhas = DebtScheduleCalculator::schedule($sistema, $depois, $monthlyRate, min($prazo, $remainingTerm), $fees);

        return [
            'paid_off' => false,
            'term' => count($linhas),
            'first_installment' => $linhas[0]['amount'],
            'total_to_pay' => self::sum($linhas, 'amount'),
            'interest' => self::sum($linhas, 'interest'),
            'balance_after' => $depois,
        ];
    }

    /**
     * Lance no consórcio: abate o saldo que falta pagar. Duas formas de usar: baixar a parcela (mantém o prazo) ou terminar antes
     * (mantém a parcela cheia). A parcela cheia é o saldo dividido pelas parcelas que restam, mais o seguro. É estimativa: a
     * administradora define as regras do lance e do abatimento.
     *
     * @return array{paid_off: bool, full_installment: string, reduce_installment: array{term: int, installment: string}, reduce_term: array{term: int, installment: string}}
     */
    public static function bid(string $balance, int $remaining, string $insurance, string $bid): array
    {
        if ($remaining < 1 || bccomp($balance, '0', 2) <= 0) {
            throw new InvalidArgumentException('Informe o saldo e as parcelas que faltam.');
        }

        $base = bcdiv($balance, (string) $remaining, 6);
        $cheia = bcadd(bcadd($base, $insurance, 6), '0.005', 2);

        if (bccomp($bid, $balance, 2) >= 0) {
            return ['paid_off' => true, 'full_installment' => $cheia, 'reduce_installment' => ['term' => 0, 'installment' => '0.00'], 'reduce_term' => ['term' => 0, 'installment' => '0.00']];
        }

        $depois = bcsub($balance, $bid, 2);
        $parcelaMenor = bcadd(bcadd(bcdiv($depois, (string) $remaining, 6), $insurance, 6), '0.005', 2);
        $prazoMenor = max(1, (int) ceil((float) bcdiv($depois, $base, 8)));

        return [
            'paid_off' => false,
            'full_installment' => $cheia,
            'reduce_installment' => ['term' => $remaining, 'installment' => $parcelaMenor],
            'reduce_term' => ['term' => $prazoMenor, 'installment' => $cheia],
        ];
    }

    /**
     * Portabilidade ou refinanciamento: o mesmo saldo, com taxa, prazo e taxas fixas novos. O custo da operação entra no total.
     *
     * @return array{term: int, first_installment: string, total_to_pay: string, interest: string, upfront: string}
     */
    public static function port(DebtAmortization $sistema, string $balance, string $newMonthlyRate, int $newTerm, string $newFees, string $upfrontCost): array
    {
        self::requireRate($sistema);

        $linhas = DebtScheduleCalculator::schedule($sistema, $balance, $newMonthlyRate, $newTerm, $newFees);

        return [
            'term' => count($linhas),
            'first_installment' => $linhas[0]['amount'],
            'total_to_pay' => bcadd(self::sum($linhas, 'amount'), $upfrontCost, 2),
            'interest' => self::sum($linhas, 'interest'),
            'upfront' => bcadd($upfrontCost, '0', 2),
        ];
    }

    /**
     * Taxa mensal implícita de um empréstimo de parcelas informadas: a taxa que faz as parcelas valerem o valor tomado (a TIR).
     * É taxa, não dinheiro, então a busca é em ponto flutuante. Nulo quando não há como calcular.
     *
     * @param  list<string>  $amounts  as parcelas, na ordem
     * @return string|null fração mensal com 8 casas ("0.02150000")
     */
    public static function impliedMonthlyRate(string $principal, array $amounts): ?string
    {
        $valor = (float) $principal;
        $n = count($amounts);

        if ($valor <= 0 || $n === 0) {
            return null;
        }

        $total = array_sum(array_map('floatval', $amounts));

        if ($total <= $valor) {
            return '0.00000000';
        }

        $presente = function (float $taxa) use ($amounts): float {
            $soma = 0.0;

            foreach ($amounts as $k => $a) {
                $soma += (float) $a / pow(1 + $taxa, $k + 1);
            }

            return $soma;
        };

        [$baixo, $alto] = [0.0, 1.0];

        // Mesmo 100% ao mês não paga o valor tomado: dado fora do razoável, não chuta uma taxa.
        if ($presente($alto) > $valor) {
            return null;
        }

        for ($i = 0; $i < 80; $i++) {
            $meio = ($baixo + $alto) / 2;
            $presente($meio) > $valor ? $baixo = $meio : $alto = $meio;
        }

        return number_format(($baixo + $alto) / 2, 8, '.', '');
    }

    /** A taxa anual equivalente (em %), só para mostrar. */
    public static function annualPercent(string $monthlyRate): float
    {
        return (pow(1 + (float) $monthlyRate, 12) - 1) * 100;
    }

    /** Parcelas de uma Price que mantém `$parcela` depois de o saldo cair: n = -ln(1 - S·i/P) / ln(1+i). */
    private static function priceTermFor(string $saldo, string $i, string $parcela, int $limite): int
    {
        if (bccomp($i, '0', 12) <= 0) {
            return max(1, (int) ceil((float) bcdiv($saldo, $parcela, 8)));
        }

        $razao = 1 - (float) $saldo * (float) $i / (float) $parcela;

        // A parcela não cobre nem os juros do saldo: não encurta, mantém o prazo.
        if ($razao <= 0) {
            return $limite;
        }

        return max(1, (int) ceil(-log($razao) / log(1 + (float) $i)));
    }

    private static function requireRate(DebtAmortization $sistema): void
    {
        if ($sistema === DebtAmortization::Fixed) {
            throw new InvalidArgumentException('A simulação precisa de taxa de juros: parcelas informadas não a têm.');
        }
    }

    /** @param list<array<string, string>> $linhas */
    private static function sum(array $linhas, string $campo): string
    {
        $total = '0.00';

        foreach ($linhas as $l) {
            $total = bcadd($total, $l[$campo], 2);
        }

        return $total;
    }
}
