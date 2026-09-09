<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InvestorType: string
{
    use HasOptions;

    case Conservative = 'conservative';
    case Moderate = 'moderate';
    case Aggressive = 'aggressive';

    public function label(): string
    {
        return match ($this) {
            self::Conservative => 'Conservador',
            self::Moderate => 'Moderado',
            self::Aggressive => 'Arrojado',
        };
    }

    /**
     * Carteira recomendada padrão — a mesma regra pra todo cliente com
     * este perfil, sem ajuste manual por enquanto (não existe tela pra
     * isso ainda). Sincronizada sempre que o perfil de investidor é
     * salvo, ver InvestmentsIndex::saveInvestorProfile(). Não cobre
     * AllocationAssetClass::International de propósito — fica sem meta
     * (0%) se o cliente tiver ativo no exterior, é só o que a regra
     * definiu. Cada conjunto soma exatamente 100%.
     *
     * @return array<string, string> AllocationAssetClass->value => percentual
     */
    public function recommendedAllocations(): array
    {
        return match ($this) {
            self::Conservative => [
                AllocationAssetClass::FixedIncome->value => '45.00',
                AllocationAssetClass::Funds->value => '25.00',
                AllocationAssetClass::EquitiesFiis->value => '15.00',
                AllocationAssetClass::DigitalAssets->value => '5.00',
                AllocationAssetClass::FxCurrencies->value => '5.00',
                AllocationAssetClass::Etfs->value => '5.00',
            ],
            self::Moderate => [
                AllocationAssetClass::FixedIncome->value => '35.00',
                AllocationAssetClass::Funds->value => '20.00',
                AllocationAssetClass::EquitiesFiis->value => '20.00',
                AllocationAssetClass::DigitalAssets->value => '7.50',
                AllocationAssetClass::FxCurrencies->value => '7.50',
                AllocationAssetClass::Etfs->value => '10.00',
            ],
            self::Aggressive => [
                AllocationAssetClass::FixedIncome->value => '25.00',
                AllocationAssetClass::Funds->value => '15.00',
                AllocationAssetClass::EquitiesFiis->value => '25.00',
                AllocationAssetClass::DigitalAssets->value => '10.00',
                AllocationAssetClass::FxCurrencies->value => '10.00',
                AllocationAssetClass::Etfs->value => '15.00',
            ],
        };
    }
}
