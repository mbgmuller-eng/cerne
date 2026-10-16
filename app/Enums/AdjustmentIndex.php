<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AdjustmentIndex: string
{
    use HasOptions;

    case Incc = 'incc';
    case Ipca = 'ipca';
    case Igpm = 'igpm';
    case Inpc = 'inpc';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Incc => 'INCC',
            self::Ipca => 'IPCA',
            self::Igpm => 'IGP-M',
            self::Inpc => 'INPC',
            self::Other => 'Outro índice',
        };
    }
}
