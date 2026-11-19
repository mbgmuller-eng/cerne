@props(['pct', 'tamanho' => 64, 'cor' => 'text-brand-700 dark:text-brand-400'])

{{-- Anel de progresso com o percentual no meio (objetivos). Só desenho: o número vem de quem chama. --}}
@php
    $valor = min(100, max(0, (float) $pct));
    $raio = 26;
    $perimetro = 2 * M_PI * $raio;
    $falta = $perimetro * (1 - $valor / 100);
@endphp
<div {{ $attributes->class(['relative shrink-0']) }} style="width: {{ $tamanho }}px; height: {{ $tamanho }}px" role="img" aria-label="{{ round($valor) }}% concluído" data-anel>
    <svg viewBox="0 0 64 64" class="h-full w-full -rotate-90" aria-hidden="true">
        <circle cx="32" cy="32" r="{{ $raio }}" fill="none" stroke="currentColor" stroke-width="6" class="text-slate-100 dark:text-slate-700" />
        <circle cx="32" cy="32" r="{{ $raio }}" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" class="{{ $cor }}" stroke-dasharray="{{ round($perimetro, 2) }}" stroke-dashoffset="{{ round($falta, 2) }}" />
    </svg>
    <span class="absolute inset-0 flex items-center justify-center text-xs font-semibold text-slate-800 dark:text-slate-100">{{ round($valor) }}%</span>
</div>
