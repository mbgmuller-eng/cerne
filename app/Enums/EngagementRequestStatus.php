<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EngagementRequestStatus: string
{
    use HasOptions;

    case Open = 'open';
    case Answered = 'answered';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::Answered => 'Respondida',
            self::Resolved => 'Resolvida',
        };
    }
}
