<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DebtStatus: string
{
    use HasOptions;

    case Active = 'active';
    case PaidOff = 'paid_off';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Em andamento',
            self::PaidOff => 'Quitada',
        };
    }
}
