<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum GoalStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Achieved = 'achieved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Em andamento',
            self::Achieved => 'Conquistado',
            self::Cancelled => 'Cancelado',
        };
    }
}
