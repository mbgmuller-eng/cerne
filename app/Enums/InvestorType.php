<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InvestorType: string
{
    use HasOptions;

    case Conservative = 'conservative';
    case Moderate = 'moderate';
    case Aggressive = 'aggressive';
    case Entrepreneur = 'entrepreneur';

    public function label(): string
    {
        return match ($this) {
            self::Conservative => 'Conservador',
            self::Moderate => 'Moderado',
            self::Aggressive => 'Arrojado',
            self::Entrepreneur => 'Empreendedor',
        };
    }
}
