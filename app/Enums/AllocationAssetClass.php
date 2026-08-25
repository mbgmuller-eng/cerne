<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Classes usadas na alocação RECOMENDADA por perfil de investidor
 * (recommended_allocations). É uma taxonomia mais grossa que a de
 * AssetClass, que classifica o ativo concreto na carteira.
 */
enum AllocationAssetClass: string
{
    use HasOptions;

    case FixedIncome = 'fixed_income';
    case Funds = 'funds';
    case EquitiesFiis = 'equities_fiis';
    case DigitalAssets = 'digital_assets';
    case FxCurrencies = 'fx_currencies';
    case Etfs = 'etfs';
    case International = 'international';

    public function label(): string
    {
        return match ($this) {
            self::FixedIncome => 'Renda fixa',
            self::Funds => 'Fundos',
            self::EquitiesFiis => 'Ações e FIIs',
            self::DigitalAssets => 'Ativos digitais',
            self::FxCurrencies => 'Moedas',
            self::Etfs => 'ETFs',
            self::International => 'Internacional',
        };
    }

    /** Cor fixa por categoria — usada no gráfico de rosca da carteira. */
    public function color(): string
    {
        return match ($this) {
            self::FixedIncome => '#0f766e',
            self::Funds => '#6366f1',
            self::EquitiesFiis => '#d97706',
            self::DigitalAssets => '#9333ea',
            self::FxCurrencies => '#0891b2',
            self::Etfs => '#e11d48',
            self::International => '#475569',
        };
    }
}
