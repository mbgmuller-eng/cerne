<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum GymMuscleGroup: string
{
    use HasOptions;

    case Chest = 'chest';
    case Back = 'back';
    case Shoulders = 'shoulders';
    case Arms = 'arms';
    case Forearms = 'forearms';
    case Legs = 'legs';
    case Glutes = 'glutes';
    case Core = 'core';
    case Cardio = 'cardio';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Chest => 'Peito',
            self::Back => 'Costas',
            self::Shoulders => 'Ombros',
            self::Arms => 'Braços',
            self::Forearms => 'Antebraço',
            self::Legs => 'Pernas',
            self::Glutes => 'Glúteos',
            self::Core => 'Core',
            self::Cardio => 'Condicionamento',
            self::Other => 'Outro',
        };
    }
}
