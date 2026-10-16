<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DebtStatus: string
{
    use HasOptions;

    case Active = 'active';
    case PaidOff = 'paid_off';
    case Open = 'open';
    case Negotiating = 'negotiating';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Em andamento',
            self::PaidOff => 'Quitada',
            self::Open => 'Em aberto',
            self::Negotiating => 'Em negociação',
        };
    }

    /** Dívida sem cronograma ainda: o valor está lá, mas não há parcela nem pagamento. */
    public function isUnscheduled(): bool
    {
        return in_array($this, [self::Open, self::Negotiating], true);
    }
}
