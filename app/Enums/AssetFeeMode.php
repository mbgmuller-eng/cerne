<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Sobre o que incide o percentual cobrado do cliente. */
enum AssetFeeMode: string
{
    use HasOptions;

    case Total = 'total';
    case Rules = 'rules';

    public function label(): string
    {
        return match ($this) {
            self::Total => 'Todo o patrimônio, um só percentual',
            self::Rules => 'Só os produtos das minhas regras',
        };
    }
}
