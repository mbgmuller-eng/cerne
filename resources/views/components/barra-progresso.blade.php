@props(['progresso', 'cor', 'hoje' => null, 'altura' => 'h-2'])

{{--
    Barra de progresso com o marcador "Hoje" (opcional): um risco no ponto em que o MÊS está (dia 19 de 30 = 63%). Comparar o risco com a barra mostra
    na hora se o gasto está no ritmo do mês ou à frente dele. Só faz sentido no mês corrente: `hoje` vem nulo nos outros meses.
--}}
<div {{ $attributes->class(['relative']) }}>
    <div class="{{ $altura }} overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
        <div class="h-full rounded-full {{ $cor }}" style="width: {{ min(100, max(0, (float) $progresso)) }}%"></div>
    </div>
    @if ($hoje !== null)
        <span class="absolute -top-1 -bottom-1 w-0.5 rounded bg-slate-600/70 dark:bg-slate-200/70" style="left: {{ $hoje }}%" title="Hoje: dia {{ now()->day }} de {{ now()->daysInMonth }}" data-marcador-hoje></span>
    @endif
</div>
