<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InviteStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando aceite',
            self::Accepted => 'Aceito',
            self::Expired => 'Expirado',
        };
    }
}
