<?php

namespace App\Support;

use App\Enums\MemberRole;

/**
 * Cores que cada pessoa do perfil pode ter no calendário da Saúde. A paleta é fechada de propósito: a cor
 * vai para um `style` inline, então só entra o que está aqui (nada digitado livremente).
 */
final class MemberPalette
{
    /** @var array<string, string> hex => nome */
    public const COLORS = [
        '#2563EB' => 'Azul',
        '#DB2777' => 'Rosa',
        '#16A34A' => 'Verde',
        '#D97706' => 'Âmbar',
        '#7C3AED' => 'Violeta',
        '#0891B2' => 'Turquesa',
        '#DC2626' => 'Vermelho',
        '#475569' => 'Grafite',
    ];

    /** Cor de quem nunca escolheu uma: titular azul, cônjuge rosa. */
    public static function defaultFor(?MemberRole $papel): string
    {
        return $papel === MemberRole::Secondary ? '#DB2777' : '#2563EB';
    }

    public static function isAllowed(string $hex): bool
    {
        return array_key_exists(strtoupper($hex), self::COLORS);
    }

    /** Valor guardado em `color_hex` só vale se for um hexadecimal de 6 dígitos (o cadastro antigo guardava qualquer coisa). */
    public static function isSafe(?string $hex): bool
    {
        return $hex !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1;
    }
}
