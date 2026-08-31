@props(['initials', 'color'])

@php
    $cor = $color ?: '#0F766E';
    $hex = ltrim($cor, '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }

    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    // Luminância relativa simplificada — cor de fundo clara (ex.: o
    // amarelo do Banco do Brasil) pede texto escuro por cima, senão some.
    $luminancia = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    $textoEscuro = $luminancia >= 0.6;
@endphp

<span
    {{ $attributes->merge(['class' => 'flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold']) }}
    style="background: {{ $cor }}; color: {{ $textoEscuro ? '#1F2937' : '#FFFFFF' }};"
>
    {{ $initials }}
</span>
