<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum EngagementMeetingFormat: string
{
    use HasOptions;

    case Video = 'video';
    case InPerson = 'in_person';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Video => 'Videochamada',
            self::InPerson => 'Presencial',
            self::Phone => 'Telefone',
        };
    }
}
