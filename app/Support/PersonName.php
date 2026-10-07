<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Comparação tolerante de nomes de pessoa: documento traz o nome completo ("MARIA DA SILVA SOUZA"),
 * o cadastro costuma ter só "Maria Souza".
 */
class PersonName
{
    /** @return list<string> palavras sem acento, sem "de/da/do..." e sem letras soltas (nome cortado no PDF) */
    public static function tokens(string $nome): array
    {
        $palavras = preg_split('/[^a-z]+/', Str::lower(Str::ascii($nome)), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter($palavras, fn (string $p) => strlen($p) > 1 && ! in_array($p, ['de', 'da', 'do', 'das', 'dos', 'e'], true)));
    }

    /**
     * Mesma pessoa quando o nome é igual, ou quando o mais curto (com ao menos duas palavras) está todo
     * contido no mais longo com o mesmo primeiro e o mesmo último nome.
     */
    public static function sameAs(string $a, string $b): bool
    {
        $a = self::tokens($a);
        $b = self::tokens($b);

        if ($a === [] || $b === []) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        [$curto, $longo] = count($a) <= count($b) ? [$a, $b] : [$b, $a];

        return count($curto) >= 2
            && array_diff($curto, $longo) === []
            && $curto[0] === $longo[0]
            && end($curto) === end($longo);
    }
}
