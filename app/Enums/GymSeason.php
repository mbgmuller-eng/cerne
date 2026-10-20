<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Em que fase da temporada do esporte a pessoa está: define quanto a academia pode pedir do corpo. */
enum GymSeason: string
{
    use HasOptions;

    case OffSeason = 'off_season';
    case PreSeason = 'pre_season';
    case InSeason = 'in_season';

    public function label(): string
    {
        return match ($this) {
            self::OffSeason => 'Fora de temporada',
            self::PreSeason => 'Pré-temporada',
            self::InSeason => 'Em competição',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::OffSeason => 'Hora de construir base: pode treinar a academia com mais volume e mais dias.',
            self::PreSeason => 'Preparando a temporada: volume normal, no máximo 4 dias de academia.',
            self::InSeason => 'Competindo ou jogando toda semana: poucos dias e menos séries, para manter a força sem cansar para o esporte.',
        };
    }

    /** O máximo de dias de academia por semana nesta fase (a pessoa pode pedir menos). */
    public function maxDays(): int
    {
        return match ($this) {
            self::OffSeason => 6,
            self::PreSeason => 4,
            self::InSeason => 3,
        };
    }

    /** Menos uma série por exercício: é manutenção, não construção. */
    public function reducesVolume(): bool
    {
        return $this === self::InSeason;
    }

    /**
     * Semanas em que a troca da fase começa a valer e em que passa a ser hora, nesta fase da temporada. A pré-temporada é curta; o resto
     * segue a faixa larga do treino comum.
     *
     * @return array{0: int, 1: int}
     */
    public function weeks(): array
    {
        return match ($this) {
            self::PreSeason => [4, 6],
            self::OffSeason, self::InSeason => [8, 12],
        };
    }
}
