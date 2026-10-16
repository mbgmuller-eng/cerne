<?php

namespace App\Support;

use App\Enums\TransactionType;
use App\Models\InvestmentTransaction;

/**
 * Refaz, em memória e sem gravar nada, a posição de um ativo (cotas, preço médio e custo) a partir das movimentações, e
 * apura o resultado de cada venda. É o mesmo cálculo de InvestmentTransactionService, e há um teste que garante que os
 * dois chegam ao mesmo resultado: o relatório de imposto de renda não pode divergir da posição que a pessoa vê.
 *
 * Regras (as mesmas do serviço): compra e subscrição somam cotas e o custo líquido (com taxas); venda reduz cotas sem mexer
 * no preço médio; desdobramento e grupamento mudam as cotas e o preço médio, mas não o custo; amortização reduz o custo.
 */
class PositionReplay
{
    private const SCALE = 6;

    public string $quantity = '0';

    public string $averagePrice = '0';

    /** Custo total de aquisição das cotas em carteira, em centavos. */
    public function cost(): string
    {
        return $this->cents(bcmul($this->quantity, $this->averagePrice, self::SCALE));
    }

    /**
     * Aplica uma movimentação. Numa venda devolve o que foi apurado; nas demais, nulo.
     *
     * @return ?array{quantity: string, gross: string, net: string, cost: string, result: string}
     */
    public function apply(InvestmentTransaction $tx): ?array
    {
        $tipo = $tx->transaction_type;
        $qtd = $this->scale($tx->quantity ?? '0');

        switch ($tipo) {
            case TransactionType::Buy:
            case TransactionType::Subscription:
                $final = bcadd($this->quantity, $qtd, self::SCALE);

                if (bccomp($final, '0', self::SCALE) !== 0) {
                    $custoTotal = bcadd(bcmul($this->quantity, $this->averagePrice, self::SCALE), $this->scale($tx->net_amount), self::SCALE);
                    $this->averagePrice = bcdiv($custoTotal, $final, self::SCALE);
                    $this->quantity = $final;
                }

                return null;

            case TransactionType::Sell:
                $custo = $this->cents(bcmul($qtd, $this->averagePrice, self::SCALE));
                $liquido = Money::parse($tx->net_amount);
                $this->quantity = bcsub($this->quantity, $qtd, self::SCALE);

                if (bccomp($this->quantity, '0', self::SCALE) < 0) {
                    $this->quantity = '0';
                }

                return [
                    'quantity' => $qtd,
                    'gross' => Money::parse($tx->total_amount),
                    'net' => $liquido,
                    'cost' => $custo,
                    'result' => bcsub($liquido, $custo, 2),
                ];

            case TransactionType::Split:
            case TransactionType::Grouping:
                if (bccomp($this->quantity, '0', self::SCALE) === 0) {
                    return null;
                }

                $custoTotal = bcmul($this->quantity, $this->averagePrice, self::SCALE);
                $final = $tx->ratio_from !== null && $tx->ratio_to !== null
                    ? bcdiv(bcmul($this->quantity, $this->scale($tx->ratio_to), self::SCALE), $this->scale($tx->ratio_from), self::SCALE)
                    : $qtd;

                if (bccomp($final, '0', self::SCALE) !== 0) {
                    $this->averagePrice = bcdiv($custoTotal, $final, self::SCALE);
                    $this->quantity = $final;
                }

                return null;

            case TransactionType::Amortization:
                if (bccomp($this->quantity, '0', self::SCALE) > 0) {
                    $custo = bcsub(bcmul($this->quantity, $this->averagePrice, self::SCALE), $this->scale($tx->net_amount), self::SCALE);
                    $this->averagePrice = bcdiv(bccomp($custo, '0', self::SCALE) < 0 ? '0' : $custo, $this->quantity, self::SCALE);
                }

                return null;

            default:
                return null; // dividendo e JCP não tocam na posição
        }
    }

    private function scale(string|float|int|null $valor): string
    {
        return bcadd((string) ($valor ?? '0'), '0', self::SCALE);
    }

    private function cents(string $valor): string
    {
        return bcadd($valor, '0.005', 2);
    }
}
