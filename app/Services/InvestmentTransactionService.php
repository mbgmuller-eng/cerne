<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\InvestmentRecord;
use App\Models\InvestmentTransaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Movimentações de investimento e recálculo de preço médio.
 *
 * A fórmula da especificação (seção 8):
 *
 *   novo_pm = (qtd_atual x pm_atual + qtd_nova x preco_novo)
 *             / (qtd_atual + qtd_nova)
 *
 * Tudo em bcmath com 6 casas. Preço médio em ponto flutuante acumula erro
 * a cada aporte, e o número que o cliente usa para calcular imposto sobre
 * ganho de capital precisa ser exato — não aproximado.
 *
 * Regras por tipo de operação:
 *   - compra    -> recalcula o preço médio e soma quantidade
 *   - venda     -> reduz quantidade, NÃO altera o preço médio
 *                  (é o que preserva a base de cálculo do ganho)
 *   - split     -> multiplica quantidade, divide o preço médio
 *   - grupamento-> divide quantidade, multiplica o preço médio
 *   - proventos -> não tocam na posição
 */
class InvestmentTransactionService
{
    private const SCALE = 6;

    /**
     * Registra uma movimentação e atualiza a posição do ativo.
     *
     * @param  array{
     *     type: TransactionType,
     *     quantity?: string|float|null,
     *     unit_price?: string|float|null,
     *     total_amount: string|float,
     *     broker_fee?: string|float|null,
     *     other_fees?: string|float|null,
     *     operation_date: CarbonImmutable,
     *     settlement_date?: ?CarbonImmutable,
     * }  $dados
     */
    public function record(InvestmentRecord $investment, array $dados, string $userId): InvestmentTransaction
    {
        $tipo = $dados['type'];
        $bruto = Money::parse($dados['total_amount']);
        $taxas = bcadd(
            Money::parse($dados['broker_fee'] ?? 0),
            Money::parse($dados['other_fees'] ?? 0),
            2,
        );

        // Na compra as taxas SOMAM ao custo; na venda, SUBTRAEM do recebido.
        $liquido = $tipo === TransactionType::Buy
            ? bcadd($bruto, $taxas, 2)
            : bcsub($bruto, $taxas, 2);

        return DB::transaction(function () use ($investment, $dados, $tipo, $bruto, $taxas, $liquido, $userId): InvestmentTransaction {
            $transacao = InvestmentTransaction::create([
                'profile_id' => $investment->profile_id,
                'member_id' => $investment->member_id,
                'investment_id' => $investment->id,
                'transaction_type' => $tipo,
                'quantity' => $dados['quantity'] ?? null,
                'unit_price' => $dados['unit_price'] ?? null,
                'total_amount' => $bruto,
                'broker_fee' => $dados['broker_fee'] ?? null,
                'other_fees' => $dados['other_fees'] ?? null,
                'net_amount' => $liquido,
                'operation_date' => $dados['operation_date'],
                'settlement_date' => $dados['settlement_date'] ?? null,
                'created_by_user_id' => $userId,
            ]);

            $this->applyToPosition($investment, $transacao);

            return $transacao;
        });
    }

    /** Aplica a movimentação à posição do ativo. */
    private function applyToPosition(InvestmentRecord $investment, InvestmentTransaction $tx): void
    {
        $tipo = $tx->transaction_type;

        if (! $tipo->affectsPosition()) {
            // Provento: entra como rendimento, não mexe na posição.
            return;
        }

        $qtdAtual = $this->scale($investment->quantity ?? '0');
        $pmAtual = $this->scale($investment->average_price ?? '0');
        $qtdOperada = $this->scale($tx->quantity ?? '0');

        match ($tipo) {
            TransactionType::Buy, TransactionType::Subscription => $this->applyBuy(
                $investment, $qtdAtual, $pmAtual, $qtdOperada, $tx
            ),
            TransactionType::Sell => $this->applySell($investment, $qtdAtual, $qtdOperada),
            TransactionType::Split => $this->applyRatio($investment, $qtdAtual, $pmAtual, $qtdOperada),
            TransactionType::Grouping => $this->applyRatio($investment, $qtdAtual, $pmAtual, $qtdOperada),
            default => null,
        };
    }

    private function applyBuy(
        InvestmentRecord $investment,
        string $qtdAtual,
        string $pmAtual,
        string $qtdNova,
        InvestmentTransaction $tx,
    ): void {
        $qtdFinal = bcadd($qtdAtual, $qtdNova, self::SCALE);

        if (bccomp($qtdFinal, '0', self::SCALE) === 0) {
            return;
        }

        // O custo da nova aquisição é o LÍQUIDO: a corretagem faz parte do
        // que se pagou pelo ativo e portanto entra no preço médio.
        $custoAntigo = bcmul($qtdAtual, $pmAtual, self::SCALE);
        $custoNovo = $this->scale($tx->net_amount);
        $custoTotal = bcadd($custoAntigo, $custoNovo, self::SCALE);

        $investment->update([
            'quantity' => $qtdFinal,
            'average_price' => bcdiv($custoTotal, $qtdFinal, self::SCALE),
            'invested_amount' => Money::parse($custoTotal),
        ]);
    }

    private function applySell(InvestmentRecord $investment, string $qtdAtual, string $qtdVendida): void
    {
        $qtdFinal = bcsub($qtdAtual, $qtdVendida, self::SCALE);

        if (bccomp($qtdFinal, '0', self::SCALE) < 0) {
            throw new \InvalidArgumentException(
                'Venda maior que a posição: não é possível vender o que não se tem.'
            );
        }

        // O preço médio NÃO muda numa venda — é ele que define o custo de
        // aquisição das cotas remanescentes para fins de imposto.
        $pm = $this->scale($investment->average_price ?? '0');

        $investment->update([
            'quantity' => $qtdFinal,
            'invested_amount' => Money::parse(bcmul($qtdFinal, $pm, self::SCALE)),
        ]);
    }

    /**
     * Desdobramento e grupamento: a posição financeira não muda, só a
     * quantidade de cotas e, na proporção inversa, o preço médio.
     *
     * `quantity` na transação carrega a quantidade FINAL de cotas.
     */
    private function applyRatio(
        InvestmentRecord $investment,
        string $qtdAtual,
        string $pmAtual,
        string $qtdFinal,
    ): void {
        if (bccomp($qtdFinal, '0', self::SCALE) === 0 || bccomp($qtdAtual, '0', self::SCALE) === 0) {
            return;
        }

        $custoTotal = bcmul($qtdAtual, $pmAtual, self::SCALE);

        $investment->update([
            'quantity' => $qtdFinal,
            'average_price' => bcdiv($custoTotal, $qtdFinal, self::SCALE),
        ]);
    }

    /**
     * Recalcula a posição do zero, a partir de todas as transações.
     *
     * Existe para corrigir um histórico importado fora de ordem — a
     * importação de nota de corretagem pode trazer operações antigas
     * depois de já haver posição registrada.
     */
    public function rebuildPosition(InvestmentRecord $investment): InvestmentRecord
    {
        $investment->update(['quantity' => '0', 'average_price' => '0', 'invested_amount' => '0']);

        $transacoes = InvestmentTransaction::withoutProfileScope()
            ->where('investment_id', $investment->id)
            ->orderBy('operation_date')
            ->orderBy('created_at')
            ->get();

        foreach ($transacoes as $tx) {
            $this->applyToPosition($investment->refresh(), $tx);
        }

        return $investment->refresh();
    }

    private function scale(string|float|int|null $valor): string
    {
        return bcadd((string) ($valor ?? '0'), '0', self::SCALE);
    }
}
