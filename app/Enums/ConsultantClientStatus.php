<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ConsultantClientStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Pending = 'pending';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Pending => 'Pendente',
            self::Inactive => 'Inativo',
        };
    }
}
