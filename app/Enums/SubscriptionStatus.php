<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * `PastDue` ainda pode conceder acesso por uma carência curta — ver
 * Subscription::isCurrent(), que é quem decide isso (depende de
 * `current_period_ends_at`, não dá pra saber só pelo status).
 */
enum SubscriptionStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativa',
            self::Trialing => 'Em teste',
            self::PastDue => 'Em atraso',
            self::Cancelled => 'Cancelada',
        };
    }
}
