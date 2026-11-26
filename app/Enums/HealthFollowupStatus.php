<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Em que pé está o acompanhamento de um problema de saúde. Resolvido fica no histórico e pode ser reaberto. */
enum HealthFollowupStatus: string
{
    use HasOptions;

    case Investigating = 'investigating';
    case Treating = 'treating';
    case Watching = 'watching';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Investigating => 'Em investigação',
            self::Treating => 'Em tratamento',
            self::Watching => 'Em observação',
            self::Resolved => 'Resolvido',
        };
    }
}
