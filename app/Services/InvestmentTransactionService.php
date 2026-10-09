<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\InvestmentTransaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

    // -----------------------------------------------------------------
    // Posição em cotas: comprar mais, vender, cotação, posição inicial
    // -----------------------------------------------------------------

    /**
     * Compra ou venda de cotas de um ativo que já existe. Recalcula o preço médio (compra) ou reduz a
     * quantidade sem mexer nele (venda) e atualiza o valor do ativo.
     *
     * O preço pago/recebido conta como a cotação mais recente: quem compra a R$ 16 hoje sabe que a cota
     * valia R$ 16 hoje. Só se a operação for ANTERIOR à última cotação conhecida é que a cotação fica como
     * está (um lançamento retroativo não pode "voltar no tempo" o valor do ativo).
     *
     * Lançamento com data anterior a outras movimentações refaz a posição pela ordem das datas
     * (rebuildPosition); se isso deixasse a posição negativa em algum ponto, nada é gravado.
     *
     * @throws \InvalidArgumentException venda maior que a posição, ou operação que não é compra nem venda
     */
    public function trade(
        InvestmentRecord $investment,
        TransactionType $tipo,
        string $quantidade,
        string $preco,
        ?string $taxas,
        CarbonImmutable $data,
        string $userId,
    ): InvestmentTransaction {
        if (! in_array($tipo, [TransactionType::Buy, TransactionType::Sell], true)) {
            throw new \InvalidArgumentException('Só compra e venda passam por aqui.');
        }

        $quantidade = $this->scale($quantidade);
        $preco = $this->scale($preco);

        if (bccomp($quantidade, '0', self::SCALE) <= 0 || bccomp($preco, '0', self::SCALE) <= 0) {
            throw new \InvalidArgumentException('Quantidade e preço precisam ser maiores que zero.');
        }

        return DB::transaction(function () use ($investment, $tipo, $quantidade, $preco, $taxas, $data, $userId): InvestmentTransaction {
            $transacao = $this->record($investment, [
                'type' => $tipo,
                'quantity' => $quantidade,
                'unit_price' => $preco,
                'total_amount' => $this->roundMoney(bcmul($quantidade, $preco, self::SCALE)),
                'broker_fee' => $taxas !== null && $taxas !== '' ? Money::parse($taxas) : null,
                'operation_date' => $data,
            ], $userId);

            $haDepois = InvestmentTransaction::withoutProfileScope()
                ->where('investment_id', $investment->id)
                ->whereDate('operation_date', '>', $data->toDateString())
                ->exists();

            $haDepois ? $this->rebuildPosition($investment) : $investment->refresh();

            $maisRecente = $investment->price_date === null || $data->startOfDay()->greaterThanOrEqualTo($investment->price_date);

            $this->storeValue(
                $investment,
                $maisRecente ? $preco : $investment->current_price,
                $maisRecente ? $data : $investment->price_date,
            );

            return $transacao;
        });
    }

    /**
     * Informa a cotação de uma data. Cotação de hoje (ou mais nova que a última) passa a ser o valor do ativo;
     * cotação de um mês passado só corrige a foto daquele mês, sem mexer no valor de agora.
     *
     * @throws \InvalidArgumentException ativo sem cotas
     */
    public function updateQuote(InvestmentRecord $investment, string $preco, CarbonImmutable $data): void
    {
        $preco = $this->scale($preco);

        if (bccomp($this->scale($investment->quantity), '0', self::SCALE) <= 0) {
            throw new \InvalidArgumentException('Este ativo não tem cotas para cotar.');
        }

        DB::transaction(function () use ($investment, $preco, $data): void {
            $maisRecente = $investment->price_date === null || $data->startOfDay()->greaterThanOrEqualTo($investment->price_date);

            if ($maisRecente) {
                $this->storeValue($investment, $preco, $data);

                return;
            }

            // Cotação antiga: só a foto do mês dela. A quantidade da época é a que a foto já tinha.
            $foto = InvestmentSnapshot::query()->where(['investment_id' => $investment->id, 'year' => $data->year, 'month' => $data->month])->first();
            $quantidade = $this->scale($foto?->quantity ?? $investment->quantity);

            InvestmentSnapshot::updateOrCreate(
                ['investment_id' => $investment->id, 'year' => $data->year, 'month' => $data->month],
                ['amount' => $this->roundMoney(bcmul($quantidade, $preco, self::SCALE)), 'quantity' => $quantidade],
            );
        });
    }

    /**
     * Ativo cadastrado só pelo valor total (sem cotas) passa a ter posição: registra a compra inicial com a
     * quantidade informada. O valor atual do ativo não muda (vira cotação implícita = valor / cotas).
     *
     * O custo total é o preço médio informado x quantidade; sem preço médio, o valor investido que o ativo já
     * tem (ou, faltando, o valor atual) dividido pelas cotas.
     *
     * @throws \InvalidArgumentException quantidade inválida ou ativo que já tem posição
     */
    public function setInitialPosition(
        InvestmentRecord $investment,
        string $quantidade,
        ?string $precoMedio,
        CarbonImmutable $data,
        string $userId,
    ): InvestmentTransaction {
        $quantidade = $this->scale($quantidade);

        if (bccomp($quantidade, '0', self::SCALE) <= 0) {
            throw new \InvalidArgumentException('Informe a quantidade de cotas.');
        }

        if (bccomp($this->scale($investment->quantity), '0', self::SCALE) > 0) {
            throw new \InvalidArgumentException('Este ativo já tem cotas registradas.');
        }

        return DB::transaction(function () use ($investment, $quantidade, $precoMedio, $data, $userId): InvestmentTransaction {
            $valorAtual = Money::parse($investment->current_amount);

            $custo = $precoMedio !== null && $precoMedio !== ''
                ? $this->roundMoney(bcmul($quantidade, $this->scale($precoMedio), self::SCALE))
                : Money::parse($investment->invested_amount ?? $investment->current_amount);

            $transacao = $this->record($investment, [
                'type' => TransactionType::Buy,
                'quantity' => $quantidade,
                'unit_price' => bcdiv($custo, $quantidade, self::SCALE),
                'total_amount' => $custo,
                'operation_date' => $data,
            ], $userId);

            $investment->refresh();

            // O valor de hoje continua o que a pessoa já tinha informado, centavo por centavo.
            $this->storeValue($investment, bcdiv($valorAtual, $quantidade, self::SCALE), CarbonImmutable::today(), keepAmount: $valorAtual);

            return $transacao;
        });
    }

    /**
     * Guarda cotação e valor num ativo recém-criado já com cotas, sem mexer na foto mensal (o cadastro novo
     * nunca criou uma; quem cria é o job do dia 1 ou a primeira atualização).
     */
    public function seedQuote(InvestmentRecord $investment, string $preco, CarbonImmutable $data): void
    {
        $investment->update(['current_price' => $this->scale($preco), 'price_date' => $data->toDateString()]);
    }

    /**
     * Grava cotação, valor (quantidade x cotação, em centavos) e a foto do mês da cotação.
     * `$keepAmount` mantém o valor exato já existente (posição inicial: o valor digitado não pode mudar
     * por arredondamento da cotação implícita).
     */
    private function storeValue(InvestmentRecord $investment, ?string $preco, CarbonInterface $dataCotacao, ?string $keepAmount = null): void
    {
        $quantidade = $this->scale($investment->quantity);

        $valor = $keepAmount
            ?? ($preco !== null ? $this->roundMoney(bcmul($quantidade, $preco, self::SCALE)) : Money::parse($investment->current_amount));

        $investment->update([
            'current_price' => $preco,
            'price_date' => $dataCotacao->toDateString(),
            'current_amount' => $valor,
        ]);

        InvestmentSnapshot::updateOrCreate(
            ['investment_id' => $investment->id, 'year' => $dataCotacao->year, 'month' => $dataCotacao->month],
            ['amount' => $valor, 'quantity' => $quantidade],
        );
    }

    /** Arredonda para centavos (meio para cima), sem passar por float. */
    private function roundMoney(string $valor): string
    {
        return bccomp($valor, '0', self::SCALE) >= 0
            ? bcadd($valor, '0.005', 2)
            : bcsub($valor, '0.005', 2);
    }

    private function scale(string|float|int|null $valor): string
    {
        return bcadd((string) ($valor ?? '0'), '0', self::SCALE);
    }
}
