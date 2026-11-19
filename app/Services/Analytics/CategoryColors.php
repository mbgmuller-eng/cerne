<?php

namespace App\Services\Analytics;

use App\Support\Charts\ChartPalette;
use Illuminate\Support\Str;

/**
 * A cor de cada categoria em um gráfico. A cor SEGUE A CATEGORIA, não a posição no ranking: cada nome prefere sempre a mesma cor (pelo próprio
 * nome), e só vai para a próxima livre quando duas categorias do mesmo gráfico caem na mesma. Assim "Habitação" é azul na rosca e nas colunas, e
 * mexer no filtro de mês quase nunca repinta as demais. "Outras" é sempre o cinza e a nona cor nunca existe.
 */
final class CategoryColors
{
    private const SLOTS = 7;

    /**
     * @param  list<string>  $nomes  na ordem em que disputam a cor (a de maior valor escolhe primeiro)
     * @return array<string, string> nome => cor
     */
    public static function assign(array $nomes, ChartPalette $p): array
    {
        $livres = range(0, self::SLOTS - 1);
        $cores = [];

        foreach ($nomes as $nome) {
            if (isset($cores[$nome])) {
                continue;
            }
            if ($nome === 'Outras') {
                $cores[$nome] = $p->gray;

                continue;
            }
            if ($livres === []) {
                $cores[$nome] = $p->gray;

                continue;
            }

            $preferida = crc32(Str::lower(Str::ascii($nome))) % self::SLOTS;
            $slot = in_array($preferida, $livres, true) ? $preferida : $livres[0];
            $livres = array_values(array_diff($livres, [$slot]));
            $cores[$nome] = $p->serie($slot);
        }

        return $cores;
    }
}
