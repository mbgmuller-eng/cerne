<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum FixedBillPaymentStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'A pagar',
            self::Paid => 'Paga',
            self::Overdue => 'Vencida',
            self::Skipped => 'Pulada',
        };
    }

    /** Entra no total de contas ainda a pagar no mês. */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Pending, self::Overdue], true);
    }
}
