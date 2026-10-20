<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum GymLevel: string
{
    use HasOptions;

    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::Beginner => 'Iniciante (menos de 6 meses)',
            self::Intermediate => 'Intermediário (6 meses a 2 anos)',
            self::Advanced => 'Avançado (mais de 2 anos)',
        };
    }
}
