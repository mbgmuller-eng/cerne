<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum IncomeStatus: string
{
    use HasOptions;

    case Expected = 'expected';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Expected => 'Previsto',
            self::Received => 'Recebido',
            self::Cancelled => 'Cancelado',
        };
    }
}
