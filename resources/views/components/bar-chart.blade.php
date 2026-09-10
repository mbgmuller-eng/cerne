@props(['meses' => [], 'series' => [], 'maximo' => 1.0, 'height' => 200, 'width' => 720, 'modo' => 'empilhado'])

{{--
    Colunas em SVG puro — mesmo espírito do sparkline/donut-chart: sem
    Chart.js nem nenhuma lib externa (hospedagem compartilhada, sem CDN).
    `series` é uma lista de ['cor', 'valores', 'rotulo', 'classe'?] — mais
    de uma entrada empilha os segmentos por padrão (`modo` 'empilhado',
    usado pela lente "Grupo" da Evolução do patrimônio) ou desenha lado a
    lado dentro do mês (`modo` 'agrupado', ex.: receitas x despesas); uma
    entrada só desenha uma coluna simples por mês ("Total"/"Ativo"). `cor`
    pode ser um hex fixo (mesma cor em claro/escuro, ex.: cor de grupo) ou
    a string literal 'currentColor' — nesse caso, informe `classe` com as
    classes `text-*`/`dark:text-*` daquela série (no modo 'empilhado' sem
    `classe`, passe a classe ao componente inteiro, igual o sparkline).

    Com muitos meses, mostrar rótulo em cada coluna lota o eixo — só
    rotula 1 a cada N (sempre incluindo o último mês).

    Eixo Y: `maximo` é o valor bruto observado, mas o topo da escala é
    arredondado pro "número redondo" mais próximo (1/2/5 × 10^k) — sem
    isso o eixo escreveria algo como "R$ 68.421" em vez de "R$ 70 mil",
    e as linhas pontilhadas existem justamente pra dar uma régua de
    referência pra ler de que valor a coluna saiu e até onde chegou. No
    modo 'agrupado', `maximo` precisa ser o maior valor INDIVIDUAL (não a
    soma do mês, que não existe nesse modo) — a escala não empilha.
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

    $nSeries = max(count($series), 1);
    $gapSubBarra = 2;
    $larguraSubBarra = max(1, ($larguraBarra - ($nSeries - 1) * $gapSubBarra) / $nSeries);

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

        @if ($modo === 'agrupado')
            {{-- Série por fora, mês por dentro — cada série vira um <g> só
                 (dá pra usar 'currentColor' + classe dark: por série, sem
                 precisar de hex fixo pra cada uma). --}}
            @foreach ($series as $sIndex => $s)
                <g class="{{ $s['classe'] ?? '' }}">
                    @foreach ($meses as $i => $mes)
                        @php
                            $valor = $s['valores'][$i] ?? 0.0;
                            $segH = max(0.0, ($valor / $escalaMaxima) * $alturaBarras);
                            $x = $areaRotulosY + $i * $larguraSlot + ($larguraSlot - $larguraBarra) / 2 + $sIndex * ($larguraSubBarra + $gapSubBarra);
                            $y = $baseY - $segH;
                        @endphp
                        @if ($segH > 0.4)
                            <rect
                                x="{{ round($x, 2) }}" y="{{ round($y, 2) }}"
                                width="{{ round($larguraSubBarra, 2) }}" height="{{ round($segH, 2) }}"
                                fill="{{ $s['cor'] }}" rx="1.5"
                            ><title>{{ $mes }}@if (($s['rotulo'] ?? '') !== '') · {{ $s['rotulo'] }}@endif: R$ {{ number_format($valor, 2, ',', '.') }}</title></rect>
                        @endif
                    @endforeach
                </g>
            @endforeach
        @else
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
            @endforeach
        @endif

        {{-- Rótulos do eixo X — independe do modo. --}}
        @foreach ($meses as $i => $mes)
            @if ($i % $passoRotuloMes === 0 || $i === $n - 1)
                <text
                    x="{{ round($areaRotulosY + $i * $larguraSlot + $larguraSlot / 2, 2) }}" y="{{ $height - 5 }}"
                    text-anchor="middle" font-size="9" class="fill-slate-400 dark:fill-slate-500"
                >{{ $mes }}</text>
            @endif
        @endforeach
    </svg>
@endif
