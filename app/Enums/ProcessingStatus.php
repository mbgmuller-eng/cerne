<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Estado de um documento na esteira de importação. */
enum ProcessingStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    /** Extraído e já confirmado pelo usuário: virou lançamento. */
    case Committed = 'committed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Na fila',
            self::Processing => 'Processando',
            self::Completed => 'Aguardando revisão',
            self::Failed => 'Falhou',
            self::Committed => 'Importado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Processing => 'stone',
            self::Completed => 'amber',
            self::Failed => 'red',
            self::Committed => 'teal',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Failed, self::Committed], true);
    }
}
