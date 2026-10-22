<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum CaregiverAccess: string
{
    use HasOptions;

    case Read = 'read';
    case Edit = 'edit';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Só leitura',
            self::Edit => 'Pode editar',
        };
    }

    public function canWrite(): bool
    {
        return $this === self::Edit;
    }
}
