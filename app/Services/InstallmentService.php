<?php

namespace App\Services;

use App\Enums\Necessity;
use App\Models\CreditCard;
use App\Models\ExpenseRecord;
use App\Models\InstallmentGroup;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Motor de compra parcelada.
 *
 * A regra central da especificação (seção 4): uma compra em N vezes NÃO
 * vira um lançamento de valor total. Vira N lançamentos, um por parcela,
 * cada um na fatura do seu ciclo. É isso que faz a fatura de cada mês
 * bater com o que o banco vai cobrar.
 *
 * Dois detalhes que decidem se o número fecha ou não:
 *
 *   1. Arredondamento. R$ 1.000 em 3x não dá 333,33 três vezes — dá
 *      999,99. A sobra de centavos vai para a ÚLTIMA parcela, que é o que
 *      as operadoras fazem e o que faz a soma bater com o total exato.
 *
 *   2. Ciclo de fatura. A primeira parcela cai na fatura correspondente à
 *      data da compra (que já pode ser a do mês seguinte, se a compra
 *      passou do fechamento); as demais avançam um ciclo por vez.
 */
class InstallmentService
{
    public function __construct(private InvoiceService $invoices) {}

    /**
     * Cria a compra parcelada e todas as suas parcelas.
     *
     * @param  array{
     *     description: string,
     *     total_amount: string|float,
     *     installments: int,
     *     purchase_date: CarbonImmutable,
     *     necessity: Necessity,
     *     category_id: string,
     *     subcategory_id?: ?string,
     *     member_id?: ?string,
     *     notes?: ?string,
     * }  $dados
     */
    public function create(CreditCard $card, array $dados, string $userId): InstallmentGroup
    {
        $parcelas = (int) $dados['installments'];
        $total = Money::parse($dados['total_amount']);

        if ($parcelas < 1 || $parcelas > config('cerne.installments.max')) {
            throw new \InvalidArgumentException(
                'Número de parcelas fora do permitido: 1 a '.config('cerne.installments.max').'.'
            );
        }

        $valores = Money::split($total, $parcelas);
        $compra = $dados['purchase_date'];

        return DB::transaction(function () use ($card, $dados, $userId, $parcelas, $total, $valores, $compra): InstallmentGroup {
            $group = InstallmentGroup::create([
                'description' => $dados['description'],
                'total_amount' => $total,
                'total_installments' => $parcelas,
                // Valor de vitrine: o que a loja anuncia. A última parcela
                // pode diferir em centavos.
                'installment_amount' => $valores[0],
                'first_installment_date' => $compra,
                'credit_card_id' => $card->id,
                'created_by_user_id' => $userId,
            ]);

            foreach ($valores as $i => $valor) {
                $numero = $i + 1;

                // Cada parcela avança um ciclo de fatura a partir da compra.
                $dataDaParcela = $compra->addMonthsNoOverflow($i);
                $fatura = $this->invoices->invoiceForPurchase($card, $dataDaParcela);

                ExpenseRecord::create([
                    'member_id' => $dados['member_id'] ?? $card->member_id,
                    'description' => $dados['description'].' ('.$numero.'/'.$parcelas.')',
                    'necessity' => $dados['necessity'],
                    'category_id' => $dados['category_id'],
                    'subcategory_id' => $dados['subcategory_id'] ?? null,
                    'amount' => $valor,
                    'expense_date' => $dataDaParcela,
                    'credit_card_id' => $card->id,
                    'credit_card_invoice_id' => $fatura->id,
                    'installment_group_id' => $group->id,
                    'installment_number' => $numero,
                    'notes' => $dados['notes'] ?? null,
                    'created_by_user_id' => $userId,
                    'is_private' => $dados['is_private'] ?? false,
                ]);
            }

            $this->recalculateAffectedInvoices($group);

            return $group->refresh();
        });
    }

    /**
     * Exclui a compra parcelada.
     *
     * Só remove as parcelas que ainda não venceram: apagar uma parcela já
     * cobrada reescreveria uma fatura fechada e o histórico deixaria de
     * corresponder ao extrato do banco.
     *
     * @return int quantas parcelas foram removidas
     */
    public function cancelRemaining(InstallmentGroup $group): int
    {
        return DB::transaction(function () use ($group): int {
            $faturas = $group->pendingInstallments()->pluck('credit_card_invoice_id');

            $removidas = 0;
            foreach ($group->pendingInstallments()->get() as $parcela) {
                $parcela->delete();
                $removidas++;
            }

            foreach ($faturas->filter()->unique() as $faturaId) {
                $fatura = \App\Models\CreditCardInvoice::find($faturaId);

                if ($fatura !== null) {
                    $this->invoices->recalculateTotal($fatura);
                }
            }

            // Grupo sem parcela nenhuma não tem por que existir.
            if ($group->installments()->count() === 0) {
                $group->delete();
            }

            return $removidas;
        });
    }

    /**
     * Recalcula os totais de todas as faturas tocadas por este grupo.
     *
     * A soma vem do banco, não de um acumulador — ver InvoiceService.
     */
    private function recalculateAffectedInvoices(InstallmentGroup $group): void
    {
        $ids = $group->installments()->pluck('credit_card_invoice_id')->filter()->unique();

        foreach ($ids as $id) {
            $fatura = \App\Models\CreditCardInvoice::find($id);

            if ($fatura !== null) {
                $this->invoices->recalculateTotal($fatura);
            }
        }
    }

    /**
     * Previsão das parcelas sem gravar nada — alimenta o resumo que a tela
     * mostra antes de o usuário confirmar.
     *
     * @return Collection<int, array{numero: int, valor: string, data: CarbonImmutable, competencia: string}>
     */
    public function preview(CreditCard $card, string|float $total, int $parcelas, CarbonImmutable $compra): Collection
    {
        $valores = Money::split(Money::parse($total), $parcelas);

        return collect($valores)->map(function (string $valor, int $i) use ($card, $compra): array {
            $data = $compra->addMonthsNoOverflow($i);
            [$ano, $mes] = $card->billingPeriodFor($data);

            return [
                'numero' => $i + 1,
                'valor' => $valor,
                'data' => $data,
                'competencia' => str_pad((string) $mes, 2, '0', STR_PAD_LEFT).'/'.$ano,
            ];
        });
    }
}
