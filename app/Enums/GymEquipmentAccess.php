<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Onde e com o quê a pessoa treina: limita os exercícios que o treino automático pode escolher. */
enum GymEquipmentAccess: string
{
    use HasOptions;

    case FullGym = 'full_gym';
    case Home = 'home';
    case Bodyweight = 'bodyweight';

    public function label(): string
    {
        return match ($this) {
            self::FullGym => 'Academia completa (máquinas, barras e halteres)',
            self::Home => 'Em casa, com halteres',
            self::Bodyweight => 'Só com o peso do corpo',
        };
    }
}
