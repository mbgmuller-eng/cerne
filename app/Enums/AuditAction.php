<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AuditAction: string
{
    use HasOptions;

    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Criou',
            self::Updated => 'Alterou',
            self::Deleted => 'Excluiu',
        };
    }
}
