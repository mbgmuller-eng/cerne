<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DebtInstallmentStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em aberto',
            self::Paid => 'Paga',
        };
    }
}
