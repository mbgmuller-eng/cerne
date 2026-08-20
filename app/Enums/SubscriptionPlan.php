<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum SubscriptionPlan: string
{
    use HasOptions;

    case Free = 'free';
    case Basic = 'basic';
    case Premium = 'premium';
    case Consultant = 'consultant';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Gratuito',
            self::Basic => 'Básico',
            self::Premium => 'Premium',
            self::Consultant => 'Consultor',
        };
    }
}
