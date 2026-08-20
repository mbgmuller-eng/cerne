<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReturnRateType: string
{
    use HasOptions;

    case Prefixed = 'prefixed';
    case PostfixedCdi = 'postfixed_cdi';
    case PostfixedIpca = 'postfixed_ipca';
    case Variable = 'variable';

    public function label(): string
    {
        return match ($this) {
            self::Prefixed => 'Prefixado',
            self::PostfixedCdi => 'Pós-fixado (CDI)',
            self::PostfixedIpca => 'Pós-fixado (IPCA)',
            self::Variable => 'Variável',
        };
    }
}
