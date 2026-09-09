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

    Eixo Y: `maximo` é o valor bruto observado, mas o topo da escala é
    arredondado pro "número redondo" mais próximo (1/2/5 × 10^k) — sem
    isso o eixo escreveria algo como "R$ 68.421" em vez de "R$ 70 mil",
    e as linhas pontilhadas existem justamente pra dar uma régua de
    referência pra ler de que valor a coluna saiu e até onde chegou.
--}}
@php
    $n = count($meses);
    $areaRotulosX = 20;
    $areaRotulosY = 54;
    $margemTopo = 9; // sem isso o rótulo do tick mais alto (y=0) é cortado pelo overflow:hidden padrão do <svg>.
    $alturaBarras = $height - $areaRotulosX - $margemTopo;
    $baseY = $margemTopo + $alturaBarras;
    $larguraDisponivel = $width - $areaRotulosY;
    $larguraSlot = $n > 0 ? $larguraDisponivel / $n : $larguraDisponivel;
    $larguraBarra = $larguraSlot * 0.6;
    $passoRotuloMes = $n > 8 ? (int) ceil($n / 8) : 1;

    $maximoBruto = $maximo > 0 ? $maximo : 1.0;
    $passoAlvo = $maximoBruto / 4;
    $grandeza = 10 ** floor(log10($passoAlvo));
    $residual = $passoAlvo / $grandeza;
    $passo = match (true) {
        $residual < 1.5 => 1 * $grandeza,
        $residual < 3 => 2 * $grandeza,
        $residual < 7 => 5 * $grandeza,
        default => 10 * $grandeza,
    };
    $escalaMaxima = $passo * ceil($maximoBruto / $passo);

    $ticks = [];
    for ($v = 0.0; $v <= $escalaMaxima + $passo * 0.01; $v += $passo) {
        $ticks[] = $v;
    }

    $abreviarMoeda = function (float $v): string {
        if ($v >= 1_000_000) {
            return 'R$ '.number_format($v / 1_000_000, 1, ',', '.').' mi';
        }
        if ($v >= 1_000) {
            return 'R$ '.number_format($v / 1_000, 0, ',', '.').' mil';
        }

        return 'R$ '.number_format($v, 0, ',', '.');
    };
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
        {{-- Grade horizontal + rótulos do eixo Y --}}
        @foreach ($ticks as $tick)
            @php $y = $baseY - ($tick / $escalaMaxima) * $alturaBarras; @endphp
            <line
                x1="{{ $areaRotulosY }}" y1="{{ round($y, 2) }}" x2="{{ $width }}" y2="{{ round($y, 2) }}"
                stroke="currentColor" stroke-width="1" stroke-dasharray="3 3"
                class="text-slate-200 dark:text-white/10"
            />
            <text
                x="{{ $areaRotulosY - 6 }}" y="{{ round($y, 2) + 3 }}"
                text-anchor="end" font-size="9" class="fill-slate-400 dark:fill-slate-500"
            >{{ $abreviarMoeda($tick) }}</text>
        @endforeach

        @foreach ($meses as $i => $mes)
            @php $x = $areaRotulosY + $i * $larguraSlot + ($larguraSlot - $larguraBarra) / 2; @endphp
            @php $yCursor = $baseY; @endphp

            @foreach ($series as $s)
                @php
                    $valor = $s['valores'][$i] ?? 0.0;
                    $segH = max(0.0, ($valor / $escalaMaxima) * $alturaBarras);
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

            @if ($i % $passoRotuloMes === 0 || $i === $n - 1)
                <text
                    x="{{ round($areaRotulosY + $i * $larguraSlot + $larguraSlot / 2, 2) }}" y="{{ $height - 5 }}"
                    text-anchor="middle" font-size="9" class="fill-slate-400 dark:fill-slate-500"
                >{{ $mes }}</text>
            @endif
        @endforeach
    </svg>
@endif
