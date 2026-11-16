<?php

namespace App\Services\Extraction;

use App\Models\ExpenseCategorizationRule;
use App\Models\FixedBillPayment;
use App\Models\IncomeCategorizationRule;
use App\Models\RecurringIncomeOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Casa a descrição de um item extraído contra as regras de categorização do
 * perfil ativo — usado tanto para pré-preencher a tela de revisão quanto
 * para decidir o que o commit() grava (ver DocumentCommitService).
 *
 * Chamado duas vezes com a mesma entrada (revisão e, depois, confirmação) —
 * é determinístico de propósito, pra revisão e commit nunca discordarem.
 */
class CategorizationRuleMatcher
{
    /**
     * PIX pode não cair exatamente no dia esperado (atraso, feriado). Uma
     * janela pequena cobre isso sem arriscar casar com a semana errada.
     */
    private const MATCH_WINDOW_DAYS = 3;

    /**
     * Conta fixa: o pagamento costuma sair ANTES do vencimento (aluguel pago uma semana antes) ou um pouco depois. O vencimento pode estar até 15 dias
     * depois do lançamento e até 10 dias antes dele. A janela maior só entra quando NADA cai na janela curta (3 dias), e só para ocorrência EM ABERTO: a mais
     * próxima na janela curta, de qualquer situação, continua mandando (conta semanal: a linha de uma semana já paga não pode casar com a da semana
     * seguinte). A pessoa continua confirmando cada linha na revisão.
     */
    public const FIXED_BILL_DAYS_BEFORE_DUE = 15;

    public const FIXED_BILL_DAYS_AFTER_DUE = 10;

    /**
     * @return array{rule: ExpenseCategorizationRule, category_id: string, subcategory_id: ?string, necessity: \App\Enums\Necessity, fixed_bill_payment: ?FixedBillPayment}|null
     */
    public function matchExpense(string $descricao, CarbonImmutable $data, ?string $valor = null): ?array
    {
        $regra = $this->bestRule(ExpenseCategorizationRule::query()->active()->get(), $descricao, $valor);

        if ($regra === null) {
            return null;
        }

        return [
            'rule' => $regra,
            'category_id' => $regra->category_id,
            'subcategory_id' => $regra->subcategory_id,
            'necessity' => $regra->necessity,
            'fixed_bill_payment' => $regra->fixed_bill_id !== null
                ? $this->closestFixedBillPayment($regra->fixed_bill_id, $data)
                : null,
        ];
    }

    /**
     * @return array{rule: IncomeCategorizationRule, category_id: string, recurring_income_occurrence: ?RecurringIncomeOccurrence}|null
     */
    public function matchIncome(string $descricao, CarbonImmutable $data, ?string $valor = null): ?array
    {
        $regra = $this->bestRule(IncomeCategorizationRule::query()->active()->get(), $descricao, $valor);

        if ($regra === null) {
            return null;
        }

        return [
            'rule' => $regra,
            'category_id' => $regra->category_id,
            'recurring_income_occurrence' => $regra->recurring_income_id !== null
                ? $this->closestOccurrence(RecurringIncomeOccurrence::query()->where('recurring_income_id', $regra->recurring_income_id), $data)
                : null,
        ];
    }

    /**
     * "Contém", sem diferenciar maiúscula/minúscula — descrição de extrato
     * sempre tem ruído (data, ID de transação) junto do nome. Quando a
     * regra tem `amount`, ele é uma condição obrigatória (não só um
     * desempate): sem valor de item pra comparar, ou com valor diferente,
     * a regra simplesmente não serve. Entre as que sobram, vence a mais
     * específica — primeiro quem tem valor travado, depois o padrão mais
     * longo; empate, a mais antiga.
     *
     * @template T of ExpenseCategorizationRule|IncomeCategorizationRule
     *
     * @param  Collection<int, T>  $regras
     * @return T|null
     */
    private function bestRule(Collection $regras, string $descricao, ?string $valor = null)
    {
        return $regras
            ->filter(fn ($regra) => mb_stripos($descricao, $regra->pattern) !== false)
            ->filter(fn ($regra) => $regra->amount === null || ($valor !== null && bccomp($regra->amount, $valor, 2) === 0))
            ->sort(fn ($a, $b) => [$b->amount !== null, mb_strlen($b->pattern), $a->created_at] <=> [$a->amount !== null, mb_strlen($a->pattern), $b->created_at])
            ->first();
    }

    /** A mais próxima na janela curta (qualquer situação); sem ela, a mais próxima EM ABERTO na janela larga (pagamento adiantado ou com atraso). */
    private function closestFixedBillPayment(string $contaFixaId, CarbonImmutable $data): ?FixedBillPayment
    {
        $janela = FixedBillPayment::query()->where('fixed_bill_id', $contaFixaId)
            ->whereBetween('due_date', [$data->subDays(self::FIXED_BILL_DAYS_AFTER_DUE)->toDateString(), $data->addDays(self::FIXED_BILL_DAYS_BEFORE_DUE)->toDateString()])
            ->get()
            ->sortBy(fn (FixedBillPayment $o) => abs($o->due_date->diffInDays($data)))
            ->values();

        return $janela->first(fn (FixedBillPayment $o) => abs($o->due_date->diffInDays($data)) <= self::MATCH_WINDOW_DAYS)
            ?? $janela->first(fn (FixedBillPayment $o) => $o->status->isOutstanding());
    }

    /**
     * @template T of FixedBillPayment|RecurringIncomeOccurrence
     *
     * @param  \Illuminate\Database\Eloquent\Builder<T>  $query
     * @return T|null
     */
    private function closestOccurrence($query, CarbonImmutable $data)
    {
        return $query
            ->whereBetween('due_date', [
                $data->subDays(self::MATCH_WINDOW_DAYS)->toDateString(),
                $data->addDays(self::MATCH_WINDOW_DAYS)->toDateString(),
            ])
            ->get()
            ->sortBy(fn ($ocorrencia) => abs($ocorrencia->due_date->diffInDays($data)))
            ->first();
    }
}
