<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DebtRateBasis: string
{
    use HasOptions;

    case Monthly = 'monthly';
    case AnnualNominal = 'annual_nominal';
    case AnnualEffective = 'annual_effective';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'ao mês',
            self::AnnualNominal => 'ao ano, nominal (dividida por 12)',
            self::AnnualEffective => 'ao ano, efetiva',
        };
    }
}
