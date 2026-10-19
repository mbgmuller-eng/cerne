<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Como o calendário mostra o tempo: o mês inteiro, a semana com os horários ou um dia só. */
enum CalendarView: string
{
    use HasOptions;

    case Month = 'month';
    case Week = 'week';
    case Day = 'day';

    public function label(): string
    {
        return match ($this) {
            self::Month => 'Mês',
            self::Week => 'Semana',
            self::Day => 'Dia',
        };
    }
}
