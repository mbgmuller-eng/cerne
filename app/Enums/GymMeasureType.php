<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * O que uma série de um exercício mede. Define quais colunas de
 * gym_set_logs importam: carga+reps (supino), tempo (prancha) ou distância
 * e tempo (esteira).
 */
enum GymMeasureType: string
{
    use HasOptions;

    case LoadReps = 'load_reps';
    case Duration = 'duration';
    case DistanceDuration = 'distance_duration';

    public function label(): string
    {
        return match ($this) {
            self::LoadReps => 'Carga e repetições',
            self::Duration => 'Tempo',
            self::DistanceDuration => 'Distância e tempo',
        };
    }

    /** Exercício medido em tempo não tem "pausa entre séries" no mesmo sentido: o app abre um cronômetro em vez de uma contagem regressiva. */
    public function usesStopwatch(): bool
    {
        return $this !== self::LoadReps;
    }
}
