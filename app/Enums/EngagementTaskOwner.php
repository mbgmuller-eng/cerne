<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EngagementTaskOwner: string
{
    use HasOptions;

    case Client = 'client';
    case Consultant = 'consultant';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Cliente',
            self::Consultant => 'Consultor',
        };
    }
}
