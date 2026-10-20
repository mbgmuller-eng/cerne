<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** O objetivo que guia o treino automático (séries, repetições, descanso e quanto condicionamento entra). */
enum GymGoal: string
{
    use HasOptions;

    case Hypertrophy = 'hypertrophy';
    case WeightLoss = 'weight_loss';
    case Strength = 'strength';
    case GeneralHealth = 'general_health';
    case Endurance = 'endurance';

    public function label(): string
    {
        return match ($this) {
            self::Hypertrophy => 'Ganhar massa muscular',
            self::WeightLoss => 'Emagrecer e condicionar',
            self::Strength => 'Ganhar força',
            self::GeneralHealth => 'Saúde geral ou começar na academia',
            self::Endurance => 'Resistência muscular',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Hypertrophy => 'Repetições moderadas (6 a 15), mais séries e descanso médio.',
            self::WeightLoss => 'Repetições mais altas, descanso curto e condicionamento no fim de cada treino.',
            self::Strength => 'Poucas repetições (3 a 6) com carga alta e descanso longo nos exercícios principais.',
            self::GeneralHealth => 'Volume moderado, todos os grupos musculares e um pouco de condicionamento.',
            self::Endurance => 'Muitas repetições (15 a 20), descanso curto e condicionamento.',
        };
    }
}
