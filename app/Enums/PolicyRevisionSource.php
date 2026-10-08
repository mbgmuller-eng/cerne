<?php

namespace App\Enums;

/** De onde veio uma versão da apólice na linha do tempo. */
enum PolicyRevisionSource: string
{
    case Created = 'created';
    /** Como a apólice estava antes da primeira mudança registrada (apólices cadastradas antes do histórico existir). */
    case Baseline = 'baseline';
    case Import = 'import';
    case Manual = 'manual';
    case Renewal = 'renewal';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Cadastro',
            self::Baseline => 'Valores anteriores',
            self::Import => 'Importação de PDF',
            self::Manual => 'Edição',
            self::Renewal => 'Renovação',
        };
    }
}
