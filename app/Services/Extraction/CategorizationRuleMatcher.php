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
     * @return array{rule: ExpenseCategorizationRule, category_id: string, subcategory_id: ?string, necessity: \App\Enums\Necessity, fixed_bill_payment: ?FixedBillPayment}|null
     */
    public function matchExpense(string $descricao, CarbonImmutable $data): ?array
    {
        $regra = $this->bestRule(ExpenseCategorizationRule::query()->active()->get(), $descricao);

        if ($regra === null) {
            return null;
        }

        return [
            'rule' => $regra,
            'category_id' => $regra->category_id,
            'subcategory_id' => $regra->subcategory_id,
            'necessity' => $regra->necessity,
            'fixed_bill_payment' => $regra->fixed_bill_id !== null
                ? $this->closestOccurrence(FixedBillPayment::query()->where('fixed_bill_id', $regra->fixed_bill_id), $data)
                : null,
        ];
    }

    /**
     * @return array{rule: IncomeCategorizationRule, category_id: string, recurring_income_occurrence: ?RecurringIncomeOccurrence}|null
     */
    public function matchIncome(string $descricao, CarbonImmutable $data): ?array
    {
        $regra = $this->bestRule(IncomeCategorizationRule::query()->active()->get(), $descricao);

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
     * sempre tem ruído (data, ID de transação) junto do nome. Quando mais de
     * uma regra bate, vence a mais específica (padrão mais longo); empate,
     * a mais antiga.
     *
     * @template T of ExpenseCategorizationRule|IncomeCategorizationRule
     *
     * @param  Collection<int, T>  $regras
     * @return T|null
     */
    private function bestRule(Collection $regras, string $descricao)
    {
        return $regras
            ->filter(fn ($regra) => mb_stripos($descricao, $regra->pattern) !== false)
            ->sort(fn ($a, $b) => [mb_strlen($b->pattern), $a->created_at] <=> [mb_strlen($a->pattern), $b->created_at])
            ->first();
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
