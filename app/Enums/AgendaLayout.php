<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * O que aparece primeiro na Agenda de Saúde: a lista (próximas e histórico) ou o calendário do mês. A tela mostra
 * os dois; isto só decide a ordem. Guardado na conta (como o tema), para valer em qualquer aparelho.
 */
enum AgendaLayout: string
{
    use HasOptions;

    case List = 'list';
    case Calendar = 'calendar';

    public function label(): string
    {
        return match ($this) {
            self::List => 'Lista primeiro',
            self::Calendar => 'Calendário primeiro',
        };
    }
}
