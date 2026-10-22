<?php

namespace App\Enums;

/** O que aconteceu com uma consulta ou exame e merece aviso a quem acompanha a pessoa. */
enum HealthAppointmentEvent: string
{
    case Created = 'created';
    case Rescheduled = 'rescheduled';
    case Updated = 'updated';
    case Cancelled = 'cancelled';
    case Done = 'done';
    case NotDone = 'not_done';
    case Reopened = 'reopened';
    case Removed = 'removed';
    case EscortAssigned = 'escort_assigned';
    case EscortReleased = 'escort_released';

    /** Título curto do aviso. */
    public function heading(): string
    {
        return match ($this) {
            self::Created => 'Nova consulta agendada',
            self::Rescheduled => 'Consulta remarcada',
            self::Updated => 'Consulta alterada',
            self::Cancelled => 'Consulta cancelada',
            self::Done => 'Consulta realizada',
            self::NotDone => 'Consulta não realizada',
            self::Reopened => 'Consulta voltou para agendada',
            self::Removed => 'Consulta removida',
            self::EscortAssigned => 'Definido quem leva',
            self::EscortReleased => 'Ninguém está levando',
        };
    }
}
