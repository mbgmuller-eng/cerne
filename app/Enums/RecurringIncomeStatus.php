<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Espelho de FixedBillPaymentStatus do lado da receita — mesmo conceito, rótulos de recebimento. */
enum RecurringIncomeStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Received = 'received';
    case Overdue = 'overdue';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'A receber',
            self::Received => 'Recebida',
            self::Overdue => 'Atrasada',
            self::Skipped => 'Pulada',
        };
    }

    /** Entra na projeção do que ainda falta receber no mês. */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Pending, self::Overdue], true);
    }
}
