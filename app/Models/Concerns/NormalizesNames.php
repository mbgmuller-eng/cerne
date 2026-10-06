<?php

namespace App\Models\Concerns;

/**
 * Comparação de nome ignorando maiúscula e acento, pra "itau" e "Itaú" (ou
 * "sulamerica" e "SulAmérica") serem o mesmo cadastro. Usado pelos
 * catálogos compartilhados com sugestão de usuário (Bank, Insurer).
 */
trait NormalizesNames
{
    public static function normalizeName(string $name): string
    {
        return strtr(mb_strtolower(trim($name)), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
    }
}
