<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** O que aconteceu com a consulta ou o exame. Nasce agendada; depois da data, quem cuida confirma se foi realizada. */
enum HealthAppointmentStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Done = 'done';
    case NotDone = 'not_done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendada',
            self::Done => 'Realizada',
            self::NotDone => 'Não realizada',
            self::Cancelled => 'Cancelada',
        };
    }

    /** Não aconteceu: cancelada ou não realizada. Fica clara no histórico e some dos lembretes. */
    public function didNotHappen(): bool
    {
        return $this === self::NotDone || $this === self::Cancelled;
    }
}
