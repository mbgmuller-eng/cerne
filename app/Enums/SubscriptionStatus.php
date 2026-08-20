<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SubscriptionStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Trialing = 'trialing';
    case Cancelled = 'cancelled';
    case PastDue = 'past_due';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Trialing => 'Em teste',
            self::Cancelled => 'Cancelada',
            self::PastDue => 'Em atraso',
        };
    }

    /** Assinaturas que ainda dão acesso ao app. */
    public function grantsAccess(): bool
    {
        return in_array($this, [self::Active, self::Trialing], true);
    }
}
