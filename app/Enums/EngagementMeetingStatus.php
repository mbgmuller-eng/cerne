<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EngagementMeetingStatus: string
{
    use HasOptions;

    case Scheduled = 'scheduled';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Agendada',
            self::Done => 'Realizada',
            self::Cancelled => 'Cancelada',
        };
    }
}
