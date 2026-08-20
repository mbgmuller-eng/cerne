<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Agrupador de primeiro nível da carteira (investment_records.sector). */
enum InvestmentSector: string
{
    use HasOptions;

    case Reserve = 'reserve';
    case Retirement = 'retirement';
    case FixedIncome = 'fixed_income';
    case VariableIncome = 'variable_income';
    case International = 'international';

    public function label(): string
    {
        return match ($this) {
            self::Reserve => 'Reserva',
            self::Retirement => 'Previdência',
            self::FixedIncome => 'Renda fixa',
            self::VariableIncome => 'Renda variável',
            self::International => 'Internacional',
        };
    }
}
