<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Como o corretor combinou a comissão da cotação: percentual do prêmio anual ou um valor fixo em reais. */
enum CommissionType: string
{
    use HasOptions;

    case Percent = 'percent';
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentual (%)',
            self::Amount => 'Valor fixo (R$)',
        };
    }
}
