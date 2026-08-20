<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum PeriodType: string
{
    use HasOptions;

    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Inception = 'inception';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensal',
            self::Yearly => 'Anual',
            self::Inception => 'Desde o início',
        };
    }

    /** Só o período mensal preenche a coluna `month`. */
    public function requiresMonth(): bool
    {
        return $this === self::Monthly;
    }
}
