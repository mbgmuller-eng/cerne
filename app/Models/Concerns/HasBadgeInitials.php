<?php

namespace App\Models\Concerns;

/**
 * Iniciais de um selo colorido a partir de um nome — banco, seguradora,
 * qualquer coisa que precise de um "logo" simples sem depender de imagem
 * (evita mexer com marca registrada de terceiro).
 */
trait HasBadgeInitials
{
    public static function initialsFor(string $name): string
    {
        $palavras = preg_split('/\s+/', trim($name)) ?: [];

        if ($palavras === [] || $palavras[0] === '') {
            return '?';
        }

        // Nome de uma palavra só ("Nubank", "Neon") usa as 2 primeiras
        // letras dela — 1 letra só colidiria nomes diferentes na mesma inicial.
        if (count($palavras) === 1) {
            return mb_strtoupper(mb_substr($palavras[0], 0, 2));
        }

        $primeiras = array_map(fn (string $p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($palavras, 0, 2));

        return implode('', $primeiras) ?: '?';
    }
}
