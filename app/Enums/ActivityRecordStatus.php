<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ActivityRecordStatus: string
{
    use HasOptions;

    /** Uma atividade avulsa marcada para um dia ("jogo combinado no sábado"). */
    case Planned = 'planned';
    case Done = 'done';
    /** O item previsto que a pessoa disse que não fez. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Previsto',
            self::Done => 'Feito',
            self::Skipped => 'Não feito',
        };
    }
}
