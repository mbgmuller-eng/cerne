@props(['labels' => [], 'series' => [], 'formato' => 'moeda', 'height' => 260])

{{--
    Série temporal (linha, área e colunas, com valores negativos) sem Chart.js nem lib externa, igual ao espírito do
    bar-chart e do sparkline. A diferença é que aqui o gráfico não "estica": as formas (linhas, áreas, colunas, grade) ficam
    num SVG em porcentagem, com traço de espessura fixa (non-scaling-stroke), e o que não pode distorcer (pontos, números
    dos eixos) é HTML posicionado em porcentagem. Assim o desenho se ajusta a qualquer largura, do celular ao monitor, e o
    texto mantém o tamanho.

    `series`: lista de ['valores' => list<?float>, 'tipo' => 'linha'|'area'|'barra', 'classe' => 'text-*', 'cor' => '#hex'?,
    'rotulo' => string, 'empilhar' => bool?]. A cor vem de `currentColor` (classe `text-*` com variante dark:) ou de `cor`.
    Valor null deixa buraco (mês sem dado). Colunas com `empilhar` somam umas sobre as outras. `formato`: 'moeda' ou 'pct'.
    A escala sempre inclui o zero.
--}}
@php
    $n = count($labels);

    $empilhado = collect($series)->contains(fn ($s) => ($s['tipo'] ?? 'linha') === 'barra' && ! empty($s['empilhar']));
    $totaisPilha = array_fill(0, max($n, 1), 0.0);
    if ($empilhado) {
        foreach ($series as $s) {
            if (($s['tipo'] ?? 'linha') === 'barra' && ! empty($s['empilhar'])) {
                foreach ($s['valores'] as $i => $v) { $totaisPilha[$i] += max((float) ($v ?? 0), 0.0); }
            }
        }
    }

    $todos = $empilhado ? $totaisPilha : [];
    foreach ($series as $s) {
        if ($empilhado && ($s['tipo'] ?? 'linha') === 'barra' && ! empty($s['empilhar'])) { continue; }
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

    // Tudo em porcentagem da área do desenho (0 a 100).
    $yPct = fn (float $v) => 100 - (($v - $escalaMin) / $faixa) * 100;
    $xPct = fn (int $i) => $n > 0 ? (($i + 0.5) / $n) * 100 : 0;
    $zero = $yPct(0.0);

    $moedaCurta = function (float $v): string {
        $sinal = $v < 0 ? '-' : '';
        $a = abs($v);
        if ($a >= 1_000_000) { return $sinal.'R$ '.number_format($a / 1_000_000, 1, ',', '.').' mi'; }
        if ($a >= 1_000) { return $sinal.'R$ '.number_format($a / 1_000, ($a >= 10_000 || fmod($a, 1_000) == 0) ? 0 : 1, ',', '.').' mil'; }

        return $sinal.'R$ '.number_format($a, 0, ',', '.');
    };
    $fmtEixo = fn (float $v) => $formato === 'pct' ? number_format($v, $passo < 1 ? 1 : 0, ',', '.').'%' : $moedaCurta($v);
    $fmtTip = fn (float $v) => $formato === 'pct' ? number_format($v, 2, ',', '.').'%' : 'R$ '.number_format($v, 2, ',', '.');

    // Rótulos do eixo X: no máximo uns 8, sempre incluindo o último.
    $passoRotulo = $n > 8 ? (int) ceil($n / 8) : 1;
    $ultimoRotulo = $n - 1;

    $barras = array_values(array_filter($series, fn ($s) => ($s['tipo'] ?? 'linha') === 'barra' && ! ($empilhado && ! empty($s['empilhar']))));
    $nBarras = max(count($barras) + ($empilhado ? 1 : 0), 1);
    $slot = $n > 0 ? 100 / $n : 100;
    $larguraBarra = $slot * 0.7;
    $larguraSub = $larguraBarra / $nBarras;
    $tamanhoPonto = $n > 26 ? 4 : 7;
@endphp

@if ($n < 2 || ! $todos)
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center text-xs text-slate-400']) }} style="height: {{ $height }}px">
        Histórico insuficiente
    </div>
@else
    <div {{ $attributes->merge(['class' => 'relative w-full']) }} style="height: {{ $height }}px" role="img">
        <div class="absolute" style="left: 56px; right: 8px; top: 10px; bottom: 26px">
            <svg class="absolute inset-0 h-full w-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                @foreach ($ticks as $tick)
                    @php $y = $yPct((float) $tick); @endphp
                    <line x1="0" x2="100" y1="{{ round($y, 3) }}" y2="{{ round($y, 3) }}" stroke="currentColor" stroke-width="1" vector-effect="non-scaling-stroke" @if (abs($tick) >= 1e-9) stroke-dasharray="3 3" @endif class="{{ abs($tick) < 1e-9 ? 'text-slate-300 dark:text-white/25' : 'text-slate-200 dark:text-white/10' }}"/>
                @endforeach

                @php $indiceBarra = 0; $acumulado = array_fill(0, max($n, 1), 0.0); @endphp
                @foreach ($series as $s)
                    @php $tipo = $s['tipo'] ?? 'linha'; $emPilha = $empilhado && $tipo === 'barra' && ! empty($s['empilhar']); @endphp

                    @if ($tipo === 'barra')
                        <g class="{{ $s['classe'] ?? '' }}" @if (! empty($s['cor'])) style="color: {{ $s['cor'] }}" @endif>
                            @foreach ($s['valores'] as $i => $v)
                                @if ($v !== null && (float) $v != 0.0)
                                    @php
                                        $v = (float) $v;
                                        if ($emPilha) {
                                            $de = $acumulado[$i];
                                            $ate = $de + max($v, 0.0);
                                            $acumulado[$i] = $ate;
                                            $x = $i * $slot + ($slot - $larguraBarra) / 2;
                                            $larg = $larguraSub;
                                            $yTopo = $yPct($ate);
                                            $yBase = $yPct($de);
                                        } else {
                                            $posicao = $indiceBarra + ($empilhado ? 1 : 0);
                                            $x = $i * $slot + ($slot - $larguraBarra) / 2 + $posicao * $larguraSub;
                                            $larg = $larguraSub;
                                            $yTopo = $yPct(max($v, 0.0));
                                            $yBase = $yPct(min($v, 0.0));
                                        }
                                    @endphp
                                    <rect x="{{ round($x, 3) }}" y="{{ round($yTopo, 3) }}" width="{{ round(max($larg - 0.15, 0.1), 3) }}" height="{{ round(max($yBase - $yTopo, 0.4), 3) }}" fill="currentColor"><title>{{ $labels[$i] }} · {{ $s['rotulo'] ?? '' }}: {{ $fmtTip($v) }}</title></rect>
                                @endif
                            @endforeach
                        </g>
                        @if (! $emPilha) @php $indiceBarra++; @endphp @endif
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
                                $atual[] = [$xPct($i), $yPct((float) $v)];
                            }
                            if ($atual) { $trechos[] = $atual; }
                        @endphp
                        <g class="{{ $s['classe'] ?? '' }}" @if (! empty($s['cor'])) style="color: {{ $s['cor'] }}" @endif>
                            @foreach ($trechos as $trecho)
                                @if ($tipo === 'area' && count($trecho) > 1)
                                    <polygon fill="currentColor" fill-opacity="0.14" points="{{ round($trecho[0][0], 3) }},{{ round($zero, 3) }} {{ collect($trecho)->map(fn ($p) => round($p[0], 3).','.round($p[1], 3))->implode(' ') }} {{ round(end($trecho)[0], 3) }},{{ round($zero, 3) }}"/>
                                @endif
                                @if (count($trecho) > 1)
                                    <polyline fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="{{ collect($trecho)->map(fn ($p) => round($p[0], 3).','.round($p[1], 3))->implode(' ') }}"/>
                                @endif
                            @endforeach
                        </g>
                    @endif
                @endforeach
            </svg>

            {{-- Pontos das linhas: HTML, para continuarem redondos em qualquer largura. --}}
            @foreach ($series as $s)
                @if (($s['tipo'] ?? 'linha') !== 'barra')
                    @foreach ($s['valores'] as $i => $v)
                        @if ($v !== null)
                            <span
                                class="absolute rounded-full {{ $s['classe'] ?? '' }}"
                                style="left: {{ round($xPct($i), 3) }}%; top: {{ round($yPct((float) $v), 3) }}%; width: {{ $tamanhoPonto }}px; height: {{ $tamanhoPonto }}px; transform: translate(-50%, -50%); background-color: currentColor; @if (! empty($s['cor'])) color: {{ $s['cor'] }}; @endif"
                                title="{{ $labels[$i] }} · {{ $s['rotulo'] ?? '' }}: {{ $fmtTip((float) $v) }}"
                            ></span>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Eixo Y --}}
            @foreach ($ticks as $tick)
                <span class="absolute whitespace-nowrap text-[10px] leading-none text-slate-400 dark:text-slate-500" style="right: 100%; padding-right: 8px; top: {{ round($yPct((float) $tick), 3) }}%; transform: translateY(-50%)">{{ $fmtEixo((float) $tick) }}</span>
            @endforeach

            {{-- Eixo X --}}
            @foreach ($labels as $i => $rotulo)
                @if ($i % $passoRotulo === 0 || $i === $ultimoRotulo)
                    @if ($i === $ultimoRotulo || $ultimoRotulo - $i >= $passoRotulo * 0.6)
                        <span class="absolute whitespace-nowrap text-[10px] leading-none text-slate-400 dark:text-slate-500" style="left: {{ round($xPct($i), 3) }}%; top: 100%; margin-top: 8px; transform: translateX(-50%)">{{ $rotulo }}</span>
                    @endif
                @endif
            @endforeach
        </div>
    </div>
@endif
