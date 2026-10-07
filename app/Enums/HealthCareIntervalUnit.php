<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum HealthCareIntervalUnit: string
{
    use HasOptions;

    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /** Plural, para o seletor: "a cada 15 [dias]". */
    public function label(): string
    {
        return match ($this) {
            self::Day => 'dias',
            self::Week => 'semanas',
            self::Month => 'meses',
            self::Year => 'anos',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Day => 'dia',
            self::Week => 'semana',
            self::Month => 'mês',
            self::Year => 'ano',
        };
    }

    /**
     * Soma o intervalo a uma data. Mês e ano sem estouro: 31/01 + 1 mês = 28/02
     * (ou 29), nunca 03/03, para a rotina não escorregar para o mês seguinte.
     */
    public function addTo(CarbonInterface $data, int $quantidade): CarbonImmutable
    {
        $base = CarbonImmutable::instance($data)->startOfDay();

        return match ($this) {
            self::Day => $base->addDays($quantidade),
            self::Week => $base->addWeeks($quantidade),
            self::Month => $base->addMonthsNoOverflow($quantidade),
            self::Year => $base->addYearsNoOverflow($quantidade),
        };
    }
}
