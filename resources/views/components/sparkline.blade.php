@props(['values' => [], 'height' => 48, 'width' => 240])

{{--
    Gráfico de linha em SVG puro — sem Chart.js nem nenhuma lib externa.
    A hospedagem compartilhada não é lugar de depender de CDN (mesma razão
    das fontes serem baixadas no build — ver vite.config.js), e pra uma
    linha simples isso é overkill mesmo fora dessa restrição.
--}}
@php
    $pontos = array_values(array_filter($values, fn ($v) => $v !== null));
    $n = count($pontos);
@endphp

@if ($n < 2)
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center text-xs text-slate-400']) }} style="height: {{ $height }}px">
        Histórico insuficiente
    </div>
@else
    @php
        $min = min($pontos);
        $max = max($pontos);
        $amplitude = ($max - $min) > 0 ? $max - $min : 1;
        $passoX = $width / ($n - 1);

        $coordenadas = [];
        foreach ($pontos as $i => $v) {
            $x = round($i * $passoX, 2);
            // Y cresce pra baixo em SVG — inverte pra o valor maior ficar no topo.
            $y = round($height - (($v - $min) / $amplitude) * $height, 2);
            $coordenadas[] = "{$x},{$y}";
        }
        $linha = implode(' ', $coordenadas);
        $area = "0,{$height} {$linha} {$width},{$height}";
    @endphp
    <svg
        {{ $attributes->merge(['class' => 'w-full text-accent-600 dark:text-accent-400']) }}
        viewBox="0 0 {{ $width }} {{ $height }}"
        preserveAspectRatio="none"
        style="height: {{ $height }}px"
        aria-hidden="true"
    >
        <polygon points="{{ $area }}" fill="currentColor" opacity="0.12"></polygon>
        <polyline points="{{ $linha }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
    </svg>
@endif
