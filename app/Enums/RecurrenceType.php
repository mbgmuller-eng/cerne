<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Periodicidade de uma conta fixa ou receita recorrente.
 *
 * Compartilhado entre despesa (FixedBill) e receita (RecurringIncome) — é
 * o mesmo conceito dos dois lados do fluxo de caixa.
 */
enum RecurrenceType: string
{
    use HasOptions;

    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Semanal',
            self::Monthly => 'Mensal',
            self::Annual => 'Anual',
        };
    }
}
