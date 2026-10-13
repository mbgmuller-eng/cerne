<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** Por que o lead não fechou, em categorias que dá para contar. A anotação livre continua ao lado. */
enum LeadLostReason: string
{
    use HasOptions;

    case Price = 'price';
    case Competitor = 'competitor';
    case GaveUp = 'gave_up';
    case NoResponse = 'no_response';
    case Declined = 'declined';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Price => 'Preço',
            self::Competitor => 'Fechou com outro',
            self::GaveUp => 'Desistiu',
            self::NoResponse => 'Parou de responder',
            self::Declined => 'Recusa da seguradora',
            self::Other => 'Outro motivo',
        };
    }
}
