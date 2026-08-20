<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Necessidade vive no LANÇAMENTO, não na categoria: o mesmo item
 * (um jantar, por exemplo) pode ser essencial ou supérfluo dependendo
 * do contexto do gasto. Ver seção 6 da especificação.
 */
enum Necessity: string
{
    use HasOptions;

    case Essential = 'essential';
    case Discretionary = 'discretionary';
    case Investment = 'investment';

    public function label(): string
    {
        return match ($this) {
            self::Essential => 'Essencial',
            self::Discretionary => 'Supérfluo',
            self::Investment => 'Investimento',
        };
    }

    /** Cor de apoio para gráficos e etiquetas. */
    public function color(): string
    {
        return match ($this) {
            self::Essential => '#22685a',
            self::Discretionary => '#B45309',
            self::Investment => '#1D4ED8',
        };
    }
}
