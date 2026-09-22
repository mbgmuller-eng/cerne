<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Datas que se repetem todo ano no mesmo dia/mês — aniversário de pessoa,
 * aniversário (renovação) de apólice. Sempre calculado a partir da data
 * ORIGINAL, nunca de "a última vez que aconteceu": não precisa rastrear
 * nenhum estado, e registrar uma renovação não muda a data-base do cálculo.
 *
 * Recebe CarbonInterface, não Carbon — casts de coluna `date` no Laravel
 * 13 devolvem Carbon\CarbonImmutable, não Illuminate\Support\Carbon; os
 * dois implementam a mesma interface, e copy()/year()/addYear() se
 * comportam igual pro que este helper precisa (a cópia nunca é descartada
 * antes de encadear).
 */
class RecurringDate
{
    /** Próxima ocorrência a partir de $from (inclusive) — nunca no passado. */
    public static function nextOccurrence(CarbonInterface $original, CarbonInterface $from): CarbonInterface
    {
        $referencia = $from->copy()->startOfDay();
        $proxima = $original->copy()->year($referencia->year)->startOfDay();

        if ($proxima->lt($referencia)) {
            $proxima = $proxima->addYear();
        }

        return $proxima;
    }

    public static function daysUntil(CarbonInterface $original, CarbonInterface $from): int
    {
        return (int) ceil($from->copy()->startOfDay()->diffInDays(self::nextOccurrence($original, $from)));
    }

    /** Quantos anos completos a data original terá na próxima ocorrência. */
    public static function yearsCompletingAt(CarbonInterface $original, CarbonInterface $from): int
    {
        return self::nextOccurrence($original, $from)->year - $original->year;
    }
}
