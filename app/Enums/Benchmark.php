<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum Benchmark: string
{
    use HasOptions;

    case Cdi = 'cdi';
    case Ipca = 'ipca';
    case Ibovespa = 'ibovespa';
    case Ifix = 'ifix';
    case Sp500 = 'sp500';

    public function label(): string
    {
        return match ($this) {
            self::Cdi => 'CDI',
            self::Ipca => 'IPCA',
            self::Ibovespa => 'Ibovespa',
            self::Ifix => 'IFIX',
            self::Sp500 => 'S&P 500',
        };
    }
}
