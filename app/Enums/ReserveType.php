<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ReserveType: string
{
    use HasOptions;

    case Paz = 'paz';
    case Oportunidade = 'oportunidade';

    public function label(): string
    {
        return match ($this) {
            self::Paz => 'Reserva de paz',
            self::Oportunidade => 'Reserva de oportunidade',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Paz => 'Cobre os custos mensais em caso de imprevisto.',
            self::Oportunidade => 'Capital disponível para aproveitar boas oportunidades.',
        };
    }
}
