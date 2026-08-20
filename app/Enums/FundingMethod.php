<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum FundingMethod: string
{
    use HasOptions;

    case LumpSum = 'lump_sum';
    case Installments = 'installments';
    case InvestmentReturn = 'investment_return';

    public function label(): string
    {
        return match ($this) {
            self::LumpSum => 'À vista',
            self::Installments => 'Parcelado',
            self::InvestmentReturn => 'Rendimento de investimento',
        };
    }
}
