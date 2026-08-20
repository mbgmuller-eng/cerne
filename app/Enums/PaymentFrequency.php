<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PaymentFrequency: string
{
    use HasOptions;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensal',
            self::Quarterly => 'Trimestral',
            self::Annual => 'Anual',
        };
    }

    /** Quantas cobranças por ano — usado para normalizar o custo mensal. */
    public function chargesPerYear(): int
    {
        return match ($this) {
            self::Monthly => 12,
            self::Quarterly => 4,
            self::Annual => 1,
        };
    }
}
