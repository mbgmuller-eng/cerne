<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LimitationStatus: string
{
    use HasOptions;

    /** Ainda sem orientação: o Cerne só sugere tirar por precaução. */
    case Investigating = 'investigating';
    /** Um profissional orientou: trocar e acrescentar passam a ser oferecidos. */
    case Guided = 'guided';
    /** Liberada: o que foi pausado pode voltar. */
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Investigating => 'Em investigação',
            self::Guided => 'Com orientação',
            self::Released => 'Liberada',
        };
    }
}
