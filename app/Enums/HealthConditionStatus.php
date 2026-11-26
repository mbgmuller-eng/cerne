<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum HealthConditionStatus: string
{
    use HasOptions;

    case Investigating = 'investigating';
    case Active = 'active';
    case Controlled = 'controlled';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Investigating => 'Em investigação',
            self::Active => 'Ativo',
            self::Controlled => 'Controlado',
            self::Resolved => 'Resolvido',
        };
    }
}
