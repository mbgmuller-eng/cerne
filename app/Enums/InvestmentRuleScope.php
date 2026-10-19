<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** A que a regra de percentual sobre patrimônio se aplica. O mais específico vale: produto, depois empresa, depois tipo de ativo. */
enum InvestmentRuleScope: string
{
    use HasOptions;

    case Product = 'product';
    case Institution = 'institution';
    case AssetClass = 'asset_class';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Produto (nome ou código)',
            self::Institution => 'Empresa (instituição)',
            self::AssetClass => 'Tipo de ativo',
        };
    }
}
