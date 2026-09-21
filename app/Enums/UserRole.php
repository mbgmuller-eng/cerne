<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum UserRole: string
{
    use HasOptions;

    case Admin = 'admin';
    case Consultant = 'consultant';
    case Broker = 'broker';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Consultant => 'Consultor',
            self::Broker => 'Corretor',
            self::Client => 'Cliente',
        };
    }
}
