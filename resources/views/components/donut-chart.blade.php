@props(['slices' => [], 'size' => 160, 'thickness' => 22])

{{--
    Rosca de composição em SVG puro — mesmo espírito do sparkline: sem
    Chart.js nem nenhuma lib externa (hospedagem compartilhada, sem CDN).
    Cada fatia é um círculo tracejado (stroke-dasharray) sobreposto ao
    anterior, técnica padrão pra donut chart sem canvas.
--}}
@php
    $raio = ($size - $thickness) / 2;
    $circunferencia = 2 * M_PI * $raio;
    $acumulado = 0.0;
@endphp

<svg {{ $attributes->merge(['class' => 'block shrink-0']) }} viewBox="0 0 {{ $size }} {{ $size }}" width="{{ $size }}" height="{{ $size }}" aria-hidden="true">
    <circle
        cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $raio }}"
        fill="none" stroke="currentColor" stroke-width="{{ $thickness }}"
        class="text-slate-100 dark:text-slate-700"
    />
    @foreach ($slices as $fatia)
        @php
            $pct = max(0, (float) $fatia['pct']);
            $comprimento = $circunferencia * ($pct / 100);
            $offset = -($acumulado / 100) * $circunferencia;
            $acumulado += $pct;
        @endphp
        @if ($pct > 0)
            <circle
                cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $raio }}"
                fill="none" stroke="{{ $fatia['color'] }}" stroke-width="{{ $thickness }}"
                stroke-dasharray="{{ round($comprimento, 2) }} {{ round($circunferencia - $comprimento, 2) }}"
                stroke-dashoffset="{{ round($offset, 2) }}"
                transform="rotate(-90 {{ $size / 2 }} {{ $size / 2 }})"
            />
        @endif
    @endforeach
</svg>
