<?php

namespace App\Enums;

/** O que aconteceu com um item de cuidado (aparelho, palmilha, óculos...) e merece aviso a quem acompanha a pessoa. */
enum HealthCareItemEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Done = 'done';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case Removed = 'removed';

    public function heading(): string
    {
        return match ($this) {
            self::Created => 'Novo item de cuidado',
            self::Updated => 'Item de cuidado alterado',
            self::Done => 'Troca registrada',
            self::Paused => 'Item de cuidado pausado',
            self::Resumed => 'Item de cuidado retomado',
            self::Removed => 'Item de cuidado removido',
        };
    }
}
