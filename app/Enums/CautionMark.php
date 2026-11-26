<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** O que ficou decidido sobre um exercício do treino diante de uma limitação. */
enum CautionMark: string
{
    use HasOptions;

    case Ok = 'ok';
    case Adapt = 'adapt';
    case Avoid = 'avoid';
    /** A própria pessoa escolheu manter: o Cerne para de sugerir. */
    case Kept = 'kept';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Pode',
            self::Adapt => 'Adaptar',
            self::Avoid => 'Evitar',
            self::Kept => 'Mantido por você',
        };
    }

    /** As que a pessoa passa da folha do profissional. */
    public static function fromProfessional(): array
    {
        return [self::Ok, self::Adapt, self::Avoid];
    }
}
