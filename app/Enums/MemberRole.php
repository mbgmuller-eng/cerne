<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MemberRole: string
{
    use HasOptions;

    case Primary = 'primary';
    case Secondary = 'secondary';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Titular',
            self::Secondary => 'Cônjuge',
        };
    }
}
