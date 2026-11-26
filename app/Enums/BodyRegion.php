<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** A região de uma limitação física. */
enum BodyRegion: string
{
    use HasOptions;

    case Neck = 'neck';
    case UpperBack = 'upper_back';
    case LowerBack = 'lower_back';
    case Hip = 'hip';
    case Thigh = 'thigh';
    case Knee = 'knee';
    case Ankle = 'ankle';
    case Shoulder = 'shoulder';
    case Elbow = 'elbow';
    case Wrist = 'wrist';

    public function label(): string
    {
        return match ($this) {
            self::Neck => 'Pescoço',
            self::UpperBack => 'Coluna torácica',
            self::LowerBack => 'Lombar',
            self::Hip => 'Quadril',
            self::Thigh => 'Coxa',
            self::Knee => 'Joelho',
            self::Ankle => 'Tornozelo',
            self::Shoulder => 'Ombro',
            self::Elbow => 'Cotovelo',
            self::Wrist => 'Punho',
        };
    }

    /**
     * O que vem marcado em "o que evitar" ao escolher a região. É só um ponto de partida por precaução: a pessoa ajusta, e a orientação do
     * profissional é o que vale.
     *
     * @return list<MovementLoad>
     */
    public function suggestedAvoid(): array
    {
        return match ($this) {
            self::Neck => [MovementLoad::Overhead, MovementLoad::SpinalLoad],
            self::UpperBack => [MovementLoad::SpinalLoad, MovementLoad::Rotation, MovementLoad::Overhead],
            self::LowerBack => [MovementLoad::Impact, MovementLoad::SpinalLoad, MovementLoad::Rotation, MovementLoad::Flexion],
            self::Hip, self::Thigh, self::Knee, self::Ankle => [MovementLoad::Impact],
            self::Shoulder => [MovementLoad::Overhead],
            self::Elbow, self::Wrist => [],
        };
    }

    /** As três regiões que o treino automático já reconhecia pelo nome do exercício. Nas outras vale só o "o que evitar". */
    public function gymRestriction(): ?GymRestriction
    {
        return match ($this) {
            self::Knee => GymRestriction::Knee,
            self::Shoulder => GymRestriction::Shoulder,
            self::LowerBack => GymRestriction::LowerBack,
            default => null,
        };
    }
}
