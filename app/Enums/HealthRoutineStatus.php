<?php

namespace App\Enums;

/** Situação de uma rotina preventiva (calculada pela próxima data; não é gravada). */
enum HealthRoutineStatus: string
{
    case Never = 'never';
    case Late = 'late';
    case Due = 'due';
    case Ok = 'ok';

    public function label(): string
    {
        return match ($this) {
            self::Never => 'Sem registro',
            self::Late => 'Atrasado',
            self::Due => 'Vencendo',
            self::Ok => 'Em dia',
        };
    }
}
