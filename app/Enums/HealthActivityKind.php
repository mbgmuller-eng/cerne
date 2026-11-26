<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** O tipo de atividade de um programa orientado por um profissional. */
enum HealthActivityKind: string
{
    use HasOptions;

    case Physiotherapy = 'physio';
    case Pilates = 'pilates';
    case Gym = 'gym';
    case Walking = 'walking';
    case Home = 'home';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Physiotherapy => 'Fisioterapia',
            self::Pilates => 'Pilates',
            self::Gym => 'Academia',
            self::Walking => 'Caminhada',
            self::Home => 'Em casa',
            self::Other => 'Outra',
        };
    }
}
