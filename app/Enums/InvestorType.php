<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InvestorType: string
{
    use HasOptions;

    case Conservative = 'conservative';
    case Moderate = 'moderate';
    case Aggressive = 'aggressive';

    public function label(): string
    {
        return match ($this) {
            self::Conservative => 'Conservador',
            self::Moderate => 'Moderado',
            self::Aggressive => 'Arrojado',
        };
    }
}
