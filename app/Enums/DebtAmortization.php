<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DebtAmortization: string
{
    use HasOptions;

    case Sac = 'sac';
    case Price = 'price';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Sac => 'SAC (amortização constante)',
            self::Price => 'Price (parcela fixa)',
            self::Fixed => 'Parcelas informadas',
        };
    }
}
