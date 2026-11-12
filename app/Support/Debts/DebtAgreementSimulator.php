<?php

namespace App\Support\Debts;

/**
 * Compara propostas de acordo para uma dívida em aberto (cartão atrasado, dívida a negociar): quanto cada uma custa no total, o
 * desconto sobre o saldo de hoje, a parcela do mês e, quando há uma proposta à vista, quanto custa por mês parcelar em vez de
 * pagar à vista (a taxa implícita do parcelamento).
 *
 * Dinheiro em bcmath (regra 3); só a taxa implícita é calculada em ponto flutuante, por ser taxa e não dinheiro.
 */
final class DebtAgreementSimulator
{
    /**
     * @param  string  $balance  o saldo da dívida hoje
     * @param  list<array{label: string, total: string, installments: int, down?: string}>  $proposals  total é o que se paga no fim, somando a entrada e as parcelas;
     *                                                                                                  `down` é a entrada (opcional) e `installments` são as parcelas DEPOIS dela
     * @return array{proposals: list<array<string, mixed>>, best_total: ?int, lightest_month: ?int}
     */
    public static function compare(string $balance, array $proposals): array
    {
        $linhas = [];

        foreach ($proposals as $i => $p) {
            $n = max(1, (int) $p['installments']);
            $total = bcadd($p['total'], '0', 2);
            // Entrada maior ou igual ao total não existe: o que passa do total não conta (a validação da tela já barra, isto protege a conta).
            $entrada = bccomp($p['down'] ?? '0', $total, 2) < 0 ? bcadd($p['down'] ?? '0', '0', 2) : '0.00';
            $desconto = bcsub($balance, $total, 2);

            $linhas[$i] = [
                'label' => $p['label'],
                'total' => $total,
                'down' => $entrada,
                'installments' => $n,
                // A última parcela absorve a sobra de centavos, como no acordo registrado (DebtService::registerAgreement). Com entrada, a parcela é
                // o que sobra do total dividido em N.
                'monthly' => bcdiv(bcsub($total, $entrada, 2), (string) $n, 2),
                'discount' => $desconto,
                'discount_percent' => bccomp($balance, '0', 2) > 0 ? round((float) $desconto / (float) $balance * 100, 2) : 0.0,
                'implicit_rate' => null,
            ];
        }

        // Referência para a taxa implícita: a proposta de menor número de parcelas (em geral, à vista).
        $referencia = null;

        foreach ($linhas as $i => $l) {
            if ($referencia === null || $l['installments'] < $linhas[$referencia]['installments']) {
                $referencia = $i;
            }
        }

        if ($referencia !== null) {
            foreach ($linhas as $i => $l) {
                // "Quanto custa parcelar" só faz sentido quando a proposta parcelada NÃO sai mais barata que a de referência (igual = sem juros embutidos, taxa zero). Uma parcelada mais barata
                // (comum ao comparar ofertas de datas diferentes) não tem custo de parcelamento a mostrar, e a conta daria taxa zero ou negativa.
                if ($l['installments'] > $linhas[$referencia]['installments'] && bccomp($l['total'], $linhas[$referencia]['total'], 2) >= 0) {
                    // Com entrada: o que sobra do pagamento à vista, depois de pagar a entrada hoje, é o que as parcelas precisam valer.
                    $linhas[$i]['implicit_rate'] = self::implicitRate(bcsub($linhas[$referencia]['total'], $l['down'], 2), bcsub($l['total'], $l['down'], 2), $l['installments']);
                    $linhas[$i]['reference'] = $linhas[$referencia]['label'];
                }
            }
        }

        $menorTotal = $menorMes = null;

        foreach ($linhas as $i => $l) {
            if ($menorTotal === null || bccomp($l['total'], $linhas[$menorTotal]['total'], 2) < 0) {
                $menorTotal = $i;
            }

            if ($menorMes === null || bccomp($l['monthly'], $linhas[$menorMes]['monthly'], 2) < 0) {
                $menorMes = $i;
            }
        }

        return ['proposals' => array_values($linhas), 'best_total' => $menorTotal, 'lightest_month' => $menorMes];
    }

    /**
     * Quanto o saldo chegaria a ser em `$months` meses se nada fosse negociado e a dívida crescesse `$monthlyPercent` ao mês (juros
     * e multa). É para dar noção do custo de esperar, não uma previsão do credor.
     */
    public static function ifNothingIsDone(string $balance, string $monthlyPercent, int $months = 12): string
    {
        $fator = number_format(pow(1 + (float) $monthlyPercent / 100, $months), 8, '.', '');

        return bcadd(bcmul($balance, $fator, 6), '0.005', 2);
    }

    /** A taxa mensal que faz as parcelas de `$total` valerem o `$avista`, com a 1ª parcela daqui a um mês; em %, com 2 casas. */
    private static function implicitRate(string $avista, string $total, int $parcelas): ?float
    {
        if (bccomp($avista, '0', 2) <= 0 || bccomp($total, '0', 2) <= 0) {
            return null;
        }

        $base = bcdiv($total, (string) $parcelas, 2);
        $resto = bcsub($total, bcmul($base, (string) $parcelas, 2), 2);
        $parcelasValor = array_fill(0, $parcelas, $base);
        $parcelasValor[$parcelas - 1] = bcadd($base, $resto, 2);

        $taxa = DebtSimulator::impliedMonthlyRate($avista, $parcelasValor);

        return $taxa === null ? null : round((float) $taxa * 100, 2);
    }
}
