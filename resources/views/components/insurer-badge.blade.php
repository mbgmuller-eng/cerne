@props(['initials', 'color' => null, 'fallback' => 'bg-brand-700'])
@php
    // Cor de marca cadastrada (Insurer) quando a seguradora tem uma; senão a
    // cor estável por nome de sempre (InsurancePolicy::colorIndexFor).
    $textoEscuro = false;

    if ($color) {
        $hex = ltrim($color, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $luminancia = (0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2))) / 255;
        $textoEscuro = $luminancia >= 0.6; // fundo claro (amarelo do BB Seguros) pede texto escuro
    }
@endphp
<span
    {{ $attributes->class(['flex shrink-0 items-center justify-center', $color ? null : $fallback, $color ? null : 'text-white']) }}
    @if ($color) style="background: {{ $color }}; color: {{ $textoEscuro ? '#1F2937' : '#FFFFFF' }};" @endif
>{{ $initials }}</span>
