@props(['labels' => [], 'series' => [], 'formato' => 'moeda', 'height' => 220, 'width' => 720])

{{--
    Série temporal em SVG puro (linha, área e colunas, com valores negativos), no mesmo espírito do bar-chart e do
    sparkline: sem Chart.js nem lib externa. `series` é uma lista de
    ['valores' => list<?float>, 'tipo' => 'linha'|'area'|'barra', 'classe' => 'text-*...', 'rotulo' => string].
    A cor vem de `currentColor` + `classe`, para trocar de tema sem hex fixo. Valor null deixa um buraco na linha (mês sem dado).
    `formato`: 'moeda' (R$ abreviado) ou 'pct' (12,3%). A escala sempre inclui o zero.
--}}
@php
    $n = count($labels);
    $areaY = 54;
    $areaX = 20;
    $topo = 9;
    $alturaUtil = $height - $areaX - $topo;
    $larguraUtil = $width - $areaY - 8;

    $todos = [];
    foreach ($series as $s) {
        foreach ($s['valores'] as $v) {
            if ($v !== null) { $todos[] = (float) $v; }
        }
    }

    $minimo = $todos ? min(0.0, min($todos)) : 0.0;
    $maximo = $todos ? max(0.0, max($todos)) : 1.0;
    if ($maximo - $minimo <= 0) { $maximo = $minimo + 1; }

    $passoAlvo = ($maximo - $minimo) / 4;
    $grandeza = 10 ** floor(log10($passoAlvo));
    $residual = $passoAlvo / $grandeza;
    $passo = match (true) {
        $residual < 1.5 => 1 * $grandeza,
        $residual < 3 => 2 * $grandeza,
        $residual < 7 => 5 * $grandeza,
        default => 10 * $grandeza,
    };
    $escalaMin = floor($minimo / $passo) * $passo;
    $escalaMax = ceil($maximo / $passo) * $passo;
    $faixa = $escalaMax - $escalaMin;

    $ticks = [];
    for ($v = $escalaMin; $v <= $escalaMax + $passo * 0.01; $v += $passo) {
        $ticks[] = round($v, 6);
    }

    $yDe = fn (float $v) => $topo + $alturaUtil - (($v - $escalaMin) / $faixa) * $alturaUtil;
    $xCentro = fn (int $i) => $areaY + ($n > 0 ? ($i + 0.5) * ($larguraUtil / $n) : 0);
    $yZero = $yDe(0.0);

    $moedaCurta = function (float $v): string {
        $sinal = $v < 0 ? '-' : '';
        $a = abs($v);
        if ($a >= 1_000_000) { return $sinal.'R$ '.number_format($a / 1_000_000, 1, ',', '.').' mi'; }
        if ($a >= 1_000) { return $sinal.'R$ '.number_format($a / 1_000, ($a >= 10_000 || fmod($a, 1_000) == 0) ? 0 : 1, ',', '.').' mil'; }

        return $sinal.'R$ '.number_format($a, 0, ',', '.');
    };
    $fmtEixo = fn (float $v) => $formato === 'pct' ? number_format($v, abs($v) < 10 && $passo < 1 ? 1 : 0, ',', '.').'%' : $moedaCurta($v);
    $fmtTip = fn (float $v) => $formato === 'pct' ? number_format($v, 2, ',', '.').'%' : 'R$ '.number_format($v, 2, ',', '.');

    $passoRotulo = $n > 8 ? (int) ceil($n / 8) : 1;
    $barras = array_values(array_filter($series, fn ($s) => ($s['tipo'] ?? 'linha') === 'barra'));
    $nBarras = max(count($barras), 1);
    $slot = $n > 0 ? $larguraUtil / $n : $larguraUtil;
    $larguraBarra = $slot * 0.7;
    $larguraSub = max(1, ($larguraBarra - ($nBarras - 1) * 2) / $nBarras);
@endphp

@if ($n < 2 || ! $todos)
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center text-xs text-slate-400']) }} style="height: {{ $height }}px">
        Histórico insuficiente
    </div>
@else
    <svg {{ $attributes->merge(['class' => 'w-full']) }} viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" role="img">
        @foreach ($ticks as $tick)
            @php $y = $yDe((float) $tick); @endphp
            <line x1="{{ $areaY }}" y1="{{ round($y, 2) }}" x2="{{ $width - 8 }}" y2="{{ round($y, 2) }}" stroke="currentColor" stroke-width="1" stroke-dasharray="{{ abs($tick) < 1e-9 ? '0' : '3 3' }}" class="{{ abs($tick) < 1e-9 ? 'text-slate-300 dark:text-white/20' : 'text-slate-200 dark:text-white/10' }}"/>
            <text x="{{ $areaY - 6 }}" y="{{ round($y, 2) + 3 }}" text-anchor="end" font-size="9" class="fill-slate-400 dark:fill-slate-500">{{ $fmtEixo((float) $tick) }}</text>
        @endforeach

        @php $indiceBarra = 0; @endphp
        @foreach ($series as $s)
            @php $tipo = $s['tipo'] ?? 'linha'; @endphp

            @if ($tipo === 'barra')
                <g class="{{ $s['classe'] ?? '' }}">
                    @foreach ($s['valores'] as $i => $v)
                        @if ($v !== null)
                            @php
                                $x = $areaY + $i * $slot + ($slot - $larguraBarra) / 2 + $indiceBarra * ($larguraSub + 2);
                                $yTopo = $yDe(max((float) $v, 0.0));
                                $yBase = $yDe(min((float) $v, 0.0));
                            @endphp
                            <rect x="{{ round($x, 2) }}" y="{{ round($yTopo, 2) }}" width="{{ round($larguraSub, 2) }}" height="{{ round(max($yBase - $yTopo, 0.6), 2) }}" fill="currentColor" rx="1.5"><title>{{ $labels[$i] }} · {{ $s['rotulo'] ?? '' }}: {{ $fmtTip((float) $v) }}</title></rect>
                        @endif
                    @endforeach
                </g>
                @php $indiceBarra++; @endphp
            @else
                @php
                    // Trechos contínuos: um mês sem dado interrompe a linha em vez de ligar os vizinhos.
                    $trechos = [];
                    $atual = [];
                    foreach ($s['valores'] as $i => $v) {
                        if ($v === null) {
                            if ($atual) { $trechos[] = $atual; $atual = []; }
                            continue;
                        }
                        $atual[] = [$xCentro($i), $yDe((float) $v), $i, (float) $v];
                    }
                    if ($atual) { $trechos[] = $atual; }
                @endphp
                <g class="{{ $s['classe'] ?? '' }}">
                    @foreach ($trechos as $trecho)
                        @if ($tipo === 'area' && count($trecho) > 1)
                            <polygon fill="currentColor" fill-opacity="0.12" points="{{ round($trecho[0][0], 2) }},{{ round($yZero, 2) }} {{ collect($trecho)->map(fn ($p) => round($p[0], 2).','.round($p[1], 2))->implode(' ') }} {{ round(end($trecho)[0], 2) }},{{ round($yZero, 2) }}"/>
                        @endif
                        @if (count($trecho) > 1)
                            <polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="{{ collect($trecho)->map(fn ($p) => round($p[0], 2).','.round($p[1], 2))->implode(' ') }}"/>
                        @endif
                        @foreach ($trecho as $p)
                            <circle cx="{{ round($p[0], 2) }}" cy="{{ round($p[1], 2) }}" r="{{ $n > 30 ? 1.8 : 3 }}" fill="currentColor"><title>{{ $labels[$p[2]] }} · {{ $s['rotulo'] ?? '' }}: {{ $fmtTip($p[3]) }}</title></circle>
                        @endforeach
                    @endforeach
                </g>
            @endif
        @endforeach

        @foreach ($labels as $i => $rotulo)
            @if ($i % $passoRotulo === 0 || $i === $n - 1)
                <text x="{{ round($xCentro($i), 2) }}" y="{{ $height - 5 }}" text-anchor="middle" font-size="9" class="fill-slate-400 dark:fill-slate-500">{{ $rotulo }}</text>
            @endif
        @endforeach
    </svg>
@endif
