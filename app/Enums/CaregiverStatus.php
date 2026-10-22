<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CaregiverStatus: string
{
    use HasOptions;

    case Invited = 'invited';
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Convite enviado',
            self::Active => 'Ativo',
            self::Revoked => 'Revogado',
        };
    }
}
