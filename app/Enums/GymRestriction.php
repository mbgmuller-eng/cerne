<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Região que a pessoa quer proteger: o treino automático deixa de fora os exercícios que mais exigem dela. */
enum GymRestriction: string
{
    use HasOptions;

    case Knee = 'knee';
    case Shoulder = 'shoulder';
    case LowerBack = 'lower_back';

    public function label(): string
    {
        return match ($this) {
            self::Knee => 'Joelho',
            self::Shoulder => 'Ombro',
            self::LowerBack => 'Lombar',
        };
    }
}
