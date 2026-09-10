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
    case Eletronicos = 'eletronicos';
    case Civil = 'civil';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Vida => 'Vida',
            self::Carro => 'Automóvel',
            self::Residencia => 'Residencial',
            self::Saude => 'Saúde',
            self::Viagem => 'Viagem',
            self::Eletronicos => 'Eletrônicos',
            self::Civil => 'Responsabilidade civil',
            self::Outro => 'Outro',
        };
    }

    /**
     * Tipos em que a apólice cobre um bem específico (não a pessoa) —
     * a tela pede o item segurado (qual carro, qual aparelho) e agrupa
     * por seguradora sem separar por membro, a não ser que haja mais de
     * um dono entre as apólices daquele tipo.
     */
    public function referencesAsset(): bool
    {
        return match ($this) {
            self::Carro, self::Eletronicos, self::Residencia => true,
            default => false,
        };
    }
}
