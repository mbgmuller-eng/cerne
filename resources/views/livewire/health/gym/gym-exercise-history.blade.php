@php
    $metrica = $analise['metric'];
    $unidade = ['kg' => 'kg', 'stack' => 'posição', 'reps' => 'reps', 'duration' => 's', 'speed' => 'km/h'][$metrica];
    $melhor = $analise['best'];
    $sugestao = $analise['suggestion'];
    $titulos = [
        'kg' => 'Maior carga total por treino (kg)',
        'stack' => 'Posição da máquina por treino',
        'reps' => 'Maior número de repetições por treino',
        'duration' => 'Maior duração por treino (s)',
        'speed' => 'Velocidade média por treino (km/h)',
    ];
@endphp

<div class="space-y-6">

    <div class="flex items-start gap-4">
        @if ($exercicio->imageUrl())
            <img src="{{ $exercicio->imageUrl() }}" alt="{{ $exercicio->name }}" class="h-20 w-20 shrink-0 rounded-xl object-cover ring-1 ring-slate-200 dark:ring-white/10">
        @endif
        <div class="min-w-0">
        <a href="{{ route('health.gym.progress') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Evolução</a>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $exercicio->name }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $exercicio->muscle_group->label() }}
            @if ($meta) · meta atual {{ $meta->target_sets }}×{{ $meta->target_reps_min && $meta->target_reps_max ? $meta->target_reps_min.'–'.$meta->target_reps_max : ($meta->target_reps_max ?? $meta->target_reps_min ?? ($meta->target_duration_seconds ? $meta->target_duration_seconds.'s' : '—')) }} @endif
        </p>
        </div>
    </div>

    @if ($sessoes === [])
        <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-300">Este exercício ainda não tem treinos finalizados.</p>
        </div>
    @else
        @if ($sugestao)
            <div @class([
                'rounded-2xl border px-5 py-4 text-sm',
                'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200' => $sugestao['type'] === 'raise',
                'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200' => $sugestao['type'] === 'plateau',
            ])>
                <p class="font-medium">{{ $sugestao['type'] === 'raise' ? 'Hora de subir a carga' : 'Platô' }}</p>
                <p class="mt-1">{{ $sugestao['message'] }}</p>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card p-5">
                <p class="eyebrow">Melhor marca</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">
                    {{ $progress->fmt($melhor['value']) }} <span class="text-base text-slate-400">{{ $unidade }}</span>
                </p>
                @if (in_array($metrica, ['kg', 'stack']) && $melhor['tiebreak'])
                    <p class="mt-1 text-xs text-slate-400">× {{ $melhor['tiebreak'] }} repetições · {{ $melhor['date']->format('d/m/Y') }}@if ($metrica === 'kg') · lados e halteres somados @endif</p>
                @else
                    <p class="mt-1 text-xs text-slate-400">{{ $melhor['date']->format('d/m/Y') }}</p>
                @endif
            </div>
            <div class="card p-5">
                <p class="eyebrow">Treinos</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ count($sessoes) }}</p>
                <p class="mt-1 text-xs text-slate-400">desde {{ $sessoes[count($sessoes) - 1]['date']->format('d/m/Y') }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Volume do último</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">
                    {{ $sessoes[0]['volume'] === '0.00' ? '—' : number_format((float) $sessoes[0]['volume'], 0, ',', '.') }}
                    @if ($sessoes[0]['volume'] !== '0.00') <span class="text-base text-slate-400">kg</span> @endif
                </p>
                <p class="mt-1 text-xs text-slate-400">carga × repetições, todas as séries</p>
            </div>
        </div>

        @if ($grafico)
            <div class="card p-5">
                <p class="eyebrow">{{ $titulos[$metrica] }}</p>
                <svg viewBox="0 0 {{ $grafico['width'] }} {{ $grafico['height'] }}" class="mt-3 h-auto w-full text-slate-400" role="img" aria-label="{{ $titulos[$metrica] }}">
                    @foreach ($grafico['yTicks'] as $tick)
                        <line x1="44" x2="{{ $grafico['width'] - 16 }}" y1="{{ round($tick['y'], 1) }}" y2="{{ round($tick['y'], 1) }}" class="stroke-slate-200 dark:stroke-white/10" stroke-width="1"/>
                        <text x="38" y="{{ round($tick['y'] + 4, 1) }}" text-anchor="end" font-size="11" fill="currentColor">{{ $tick['label'] }}</text>
                    @endforeach

                    @foreach ($grafico['breaks'] as $x)
                        <line x1="{{ round($x, 1) }}" x2="{{ round($x, 1) }}" y1="16" y2="{{ $grafico['height'] - 32 }}" class="stroke-amber-500" stroke-width="1" stroke-dasharray="4 3"/>
                    @endforeach

                    @foreach ($grafico['segments'] as $segmento)
                        @if (count($segmento) > 1)
                            <polyline fill="none" class="stroke-saude-800 dark:stroke-saude-200" stroke-width="2" stroke-linejoin="round"
                                points="{{ collect($segmento)->map(fn ($p) => round($p['x'], 1).','.round($p['y'], 1))->implode(' ') }}"/>
                        @endif
                        @foreach ($segmento as $ponto)
                            <circle cx="{{ round($ponto['x'], 1) }}" cy="{{ round($ponto['y'], 1) }}" r="4" class="fill-saude-800 dark:fill-saude-200"><title>{{ $ponto['title'] }}</title></circle>
                        @endforeach
                    @endforeach

                    @foreach ($grafico['xLabels'] as $rotulo)
                        <text x="{{ round($rotulo['x'], 1) }}" y="{{ $grafico['height'] - 10 }}" text-anchor="middle" font-size="11" fill="currentColor">{{ $rotulo['label'] }}</text>
                    @endforeach
                </svg>
                @if (count($grafico['breaks']))
                    <p class="mt-2 text-xs text-slate-400">A linha pontilhada marca troca de equipamento: a comparação recomeça a partir dali.</p>
                @endif
            </div>
        @endif

        <section>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Treino a treino</h2>
            <ul class="card mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($sessoes as $s)
                    <li class="px-5 py-3" wire:key="h-{{ $s['session_id'] }}">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm text-slate-800 dark:text-slate-200">{{ $s['date']->format('d/m/Y') }}</p>
                            @if ($s['equipment_name']) <p class="text-xs text-slate-400">{{ $s['equipment_name'] }}</p> @endif
                        </div>
                        <p class="mt-1 text-xs tabular-nums text-slate-500 dark:text-slate-400">
                            @foreach ($s['sets'] as $serie)
                                @if ($serie->load_value !== null) {{ $progress->fmt($serie->load_value) }} kg × @endif
                                @if ($serie->reps) {{ $serie->reps }} @endif
                                @if ($serie->duration_seconds) {{ $serie->duration_seconds }}s @endif
                                @if ($serie->distance_meters) · {{ $serie->distance_meters }} m @endif
                                @if (! $loop->last) · @endif
                            @endforeach
                        </p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
