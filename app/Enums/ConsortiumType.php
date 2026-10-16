<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ConsortiumType: string
{
    use HasOptions;

    case Vehicle = 'vehicle';
    case Property = 'property';
    case Services = 'services';

    public function label(): string
    {
        return match ($this) {
            self::Vehicle => 'Veículo',
            self::Property => 'Imóvel',
            self::Services => 'Serviços',
        };
    }
}
