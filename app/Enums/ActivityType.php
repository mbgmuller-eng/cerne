<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** O tipo de uma atividade do diário. "Outra" leva o nome que a pessoa escrever. */
enum ActivityType: string
{
    use HasOptions;

    case Tennis = 'tennis';
    case Padel = 'padel';
    case Football = 'football';
    case Volleyball = 'volleyball';
    case Basketball = 'basketball';
    case MartialArts = 'martial_arts';
    case Walking = 'walking';
    case Running = 'running';
    case Swimming = 'swimming';
    case Cycling = 'cycling';
    case Pilates = 'pilates';
    case Dance = 'dance';
    case Gym = 'gym';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Tennis => 'Tênis',
            self::Padel => 'Padel',
            self::Football => 'Futebol',
            self::Volleyball => 'Vôlei',
            self::Basketball => 'Basquete',
            self::MartialArts => 'Lutas',
            self::Walking => 'Caminhada',
            self::Running => 'Corrida',
            self::Swimming => 'Natação',
            self::Cycling => 'Bicicleta',
            self::Pilates => 'Pilates',
            self::Dance => 'Dança',
            self::Gym => 'Academia',
            self::Other => 'Outra',
        };
    }

    /** Jogos e lutas: é a véspera deles que a regra das pernas protege. */
    public function isSport(): bool
    {
        return in_array($this, [self::Tennis, self::Padel, self::Football, self::Volleyball, self::Basketball, self::MartialArts], true);
    }

    public function hasDistance(): bool
    {
        return in_array($this, [self::Walking, self::Running, self::Swimming, self::Cycling], true);
    }

    /** O grupo no gráfico de carga: poucas cores, sempre na mesma ordem. */
    public function chartGroup(): string
    {
        return match (true) {
            $this->isSport() => 'sport',
            $this === self::Gym => 'gym',
            $this === self::Walking, $this === self::Running => 'run',
            default => 'other',
        };
    }

    /** O que a pessoa escolhe no diário. A academia entra sozinha quando o treino termina, mas também pode ser registrada à mão. */
    public static function choices(): array
    {
        return self::options();
    }
}
