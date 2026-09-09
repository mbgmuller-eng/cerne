@props(['meses' => [], 'series' => [], 'maximo' => 1.0, 'height' => 200, 'width' => 720])

{{--
    Colunas em SVG puro — mesmo espírito do sparkline/donut-chart: sem
    Chart.js nem nenhuma lib externa (hospedagem compartilhada, sem CDN).
    `series` é uma lista de ['cor', 'valores', 'rotulo'] — mais de uma
    entrada empilha os segmentos (usado pela lente "Grupo"); uma entrada
    só desenha uma coluna simples por mês ("Total"/"Ativo"). `cor` pode
    ser um hex fixo (mesma cor em claro/escuro, ex.: cor de grupo) ou a
    string literal 'currentColor', que herda a cor de texto do elemento
    — passe uma classe `text-*` ao componente pra esse caso, igual o
    sparkline faz internamente.

    Com muitos meses, mostrar rótulo em cada coluna lota o eixo — só
    rotula 1 a cada N (sempre incluindo o último mês).
--}}
@php
    $n = count($meses);
    $areaRotulos = 20;
    $alturaBarras = $height - $areaRotulos;
    $max = $maximo > 0 ? $maximo : 1.0;
    $larguraSlot = $n > 0 ? $width / $n : $width;
    $larguraBarra = $larguraSlot * 0.6;
    $passoRotulo = $n > 8 ? (int) ceil($n / 8) : 1;
@endphp

@if ($n < 1)
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center text-xs text-slate-400']) }} style="height: {{ $height }}px">
        Histórico insuficiente
    </div>
@else
    <svg
        {{ $attributes->merge(['class' => 'w-full']) }}
        viewBox="0 0 {{ $width }} {{ $height }}"
        preserveAspectRatio="none"
        role="img"
    >
        @foreach ($meses as $i => $mes)
            @php $x = $i * $larguraSlot + ($larguraSlot - $larguraBarra) / 2; @endphp
            @php $yCursor = $alturaBarras; @endphp

            @foreach ($series as $s)
                @php
                    $valor = $s['valores'][$i] ?? 0.0;
                    $segH = max(0.0, ($valor / $max) * $alturaBarras);
                    $y = $yCursor - $segH;
                @endphp
                @if ($segH > 0.4)
                    <rect
                        x="{{ round($x, 2) }}" y="{{ round($y, 2) }}"
                        width="{{ round($larguraBarra, 2) }}" height="{{ round($segH, 2) }}"
                        fill="{{ $s['cor'] }}" rx="1.5"
                    ><title>{{ $mes }}@if (($s['rotulo'] ?? '') !== '') · {{ $s['rotulo'] }}@endif: R$ {{ number_format($valor, 2, ',', '.') }}</title></rect>
                @endif
                @php $yCursor = $y; @endphp
            @endforeach

            @if ($i % $passoRotulo === 0 || $i === $n - 1)
                <text
                    x="{{ round($i * $larguraSlot + $larguraSlot / 2, 2) }}" y="{{ $height - 5 }}"
                    text-anchor="middle" font-size="9" class="fill-slate-400 dark:fill-slate-500"
                >{{ $mes }}</text>
            @endif
        @endforeach
    </svg>
@endif
