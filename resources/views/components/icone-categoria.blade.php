@props(['nome' => '', 'tamanho' => 'h-10 w-10'])

{{--
    Ícone redondo e colorido de uma categoria (como nos apps de finanças pessoais): o desenho vem de uma palavra do NOME (Alimentação, Transporte,
    Saúde...) e a cor é sempre a mesma para o mesmo nome, sem coluna nova no banco. Nome sem palavra conhecida cai na inicial.
--}}
@php
    $chave = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $nome));
    $mapa = [
        'alimenta' => ['M6 3v7a2 2 0 0 0 2 2v9M10 3v7M6 7h4M17 3c-2 2-3 5-3 8h3v10', '#C2410C'],
        'mercado' => ['M3 4h2l2 11h11l2-8H7M9 20h.01M17 20h.01', '#C2410C'],
        'habita' => ['M3 10.5 12 3l9 7.5M5 9.5V21h14V9.5M10 21v-6h4v6', '#6D28D9'],
        'moradia' => ['M3 10.5 12 3l9 7.5M5 9.5V21h14V9.5M10 21v-6h4v6', '#6D28D9'],
        'transporte' => ['M5 16V9l2-4h10l2 4v7M3 16h18M7.5 19.5h.01M16.5 19.5h.01', '#1D4ED8'],
        'carro' => ['M5 16V9l2-4h10l2 4v7M3 16h18M7.5 19.5h.01M16.5 19.5h.01', '#1D4ED8'],
        'saude' => ['M12 21s-7-4.4-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.6-9 9-9 9Z', '#BE123C'],
        'lazer' => ['M12 3l2.6 5.5 6 .8-4.4 4.2 1.1 6L12 16.6 6.7 19.5l1.1-6L3.4 9.3l6-.8L12 3Z', '#0F766E'],
        'educa' => ['M3 9l9-5 9 5-9 5-9-5ZM7 11.5V16c0 1 2.2 2.5 5 2.5s5-1.5 5-2.5v-4.5', '#4338CA'],
        'filhos' => ['M12 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM5 21v-2a5 5 0 0 1 14 0v2', '#0369A1'],
        'pets' => ['M7 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM17 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM12 20c-3 0-5-1.5-5-3.5S9 12 12 12s5 2.5 5 4.5-2 3.5-5 3.5Z', '#A16207'],
        'vestuario' => ['M8 3 3 7l3 3 2-1v12h8V9l2 1 3-3-5-4a4 4 0 0 1-8 0Z', '#BE185D'],
        'invest' => ['M3 17l5-6 4 3 5-7 4 4M3 21h18', '#15803D'],
        'financeiro' => ['M3 7h18v11H3zM3 11h18M7 15h3', '#475569'],
        'cuidados' => ['M12 3v18M3 12h18', '#0E7490'],
        'seguro' => ['M12 3l8 3v6c0 4.6-3.2 8-8 9-4.8-1-8-4.4-8-9V6l8-3ZM9 12l2 2 4-4', '#15803D'],
    ];
    $desenho = null;
    $cor = null;
    foreach ($mapa as $palavra => [$d, $c]) {
        if (str_contains($chave, $palavra)) { $desenho = $d; $cor = $c; break; }
    }
    // Sem palavra conhecida: a cor sai do próprio nome (sempre a mesma) e o ícone é a inicial.
    $paleta = ['#B45309', '#0F766E', '#7C3AED', '#BE123C', '#1D4ED8', '#047857', '#9A3412', '#4338CA'];
    $cor ??= $paleta[crc32($chave) % count($paleta)];
    $inicial = mb_strtoupper(mb_substr(trim((string) $nome) ?: '?', 0, 1));
@endphp

<span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center rounded-full', $tamanho]) }} style="background-color: {{ $cor }}1F; color: {{ $cor }}" aria-hidden="true" data-icone-categoria>
    @if ($desenho)
        <svg class="h-1/2 w-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $desenho }}"/></svg>
    @else
        <span class="text-sm font-semibold">{{ $inicial }}</span>
    @endif
</span>
