<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TutorialStatus: string
{
    use HasOptions;

    /** A pessoa abriu o tutorial da tela (pelo cartão de primeira visita ou pelo botão "?"). */
    case Viewed = 'viewed';

    /** A pessoa dispensou o cartão de primeira visita sem abrir o tutorial. */
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Viewed => 'Visto',
            self::Dismissed => 'Dispensado',
        };
    }
}
