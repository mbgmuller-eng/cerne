<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Com que base o acesso de um familiar foi liberado: a própria pessoa, ou o consultor que a representa com a autorização dela. */
enum CaregiverGrantBasis: string
{
    use HasOptions;

    case Self = 'self';
    case Authorized = 'authorized';
    case Representative = 'representative';

    public function label(): string
    {
        return match ($this) {
            self::Self => 'A própria pessoa liberou',
            self::Authorized => 'A pessoa autorizou (presencialmente ou por escrito)',
            self::Representative => 'Representante legal (procuração ou curatela)',
        };
    }
}