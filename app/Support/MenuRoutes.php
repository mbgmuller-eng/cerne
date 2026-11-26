<?php

namespace App\Support;

/** Quais telas deixam um item do menu marcado. Na maioria, só a própria; a área Atividades reúne as suas telas e as da Academia. */
final class MenuRoutes
{
    /** @return list<string> */
    public static function activePatterns(string $rota): array
    {
        return $rota === 'health.activities.index' ? ['health.activities.*', 'health.gym.*'] : [$rota];
    }
}
