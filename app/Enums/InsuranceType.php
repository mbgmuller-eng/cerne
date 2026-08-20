<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum InsuranceType: string
{
    use HasOptions;

    case Vida = 'vida';
    case Carro = 'carro';
    case Residencia = 'residencia';
    case Saude = 'saude';
    case Viagem = 'viagem';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Vida => 'Vida',
            self::Carro => 'Automóvel',
            self::Residencia => 'Residencial',
            self::Saude => 'Saúde',
            self::Viagem => 'Viagem',
            self::Outro => 'Outro',
        };
    }
}
