@php
    use App\Support\GymReportCharts;

    $unidade = ['kg' => 'kg', 'stack' => 'posição', 'reps' => 'reps', 'duration' => 's', 'speed' => 'km/h'];
    $res = $relatorio['summary'];
    $ant = $relatorio['previous'];
    $d = $relatorio['highlights'];
    $fmtMarca = fn (array $linha, array $ponto) => $progress->fmt($ponto['value']).' '.($unidade[$linha['metric']] ?? '')
        .(in_array($linha['metric'], ['kg', 'stack']) && $ponto['tiebreak'] ? ' × '.$ponto['tiebreak'] : '');
    $fmtVar = function (?string $pct) {
        if ($pct === null) { return '—'; }
        return (bccomp($pct, '0', 1) > 0 ? '+' : (bccomp($pct, '0', 1) < 0 ? '−' : '')).ltrim(str_replace('.', ',', $pct), '-').'%';
    };
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('health.gym.progress') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Evolução</a>
            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Relatório de evolução</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $relatorio['start']->format('d/m/Y') }} a {{ $relatorio['end']->format('d/m/Y') }}. Só treinos finalizados entram.
            </p>
        </div>
        <button type="button" wire:click="baixarPdf" wire:loading.attr="disabled" wire:target="baixarPdf" class="btn-secondary">
            <span wire:loading.remove wire:target="baixarPdf">Baixar em PDF</span>
            <span wire:loading wire:target="baixarPdf">Gerando...</span>
        </button>
    </div>

    <div class="card flex flex-wrap items-end gap-3 p-4">
        <div>
            <label class="{{ $campo }}">Período</label>
            <select wire:model.live="periodo" class="select mt-1.5">
                @foreach ($atalhos as $valor => $rotulo)
                    @continue($valor === 'fase' && ! $temFase)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
        @if ($periodo === 'custom')
            <div>
                <label class="{{ $campo }}">De</label>
                <input type="date" wire:model.live="inicio" class="input mt-1.5">
                @error('inicio') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $campo }}">Até</label>
                <input type="date" wire:model.live="fim" class="input mt-1.5">
                @error('fim') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>

    @if ($res['sessions'] === 0)
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum treino finalizado nesse período.</p>
            <p class="mt-1 text-xs text-slate-400">Escolha outro período ou finalize um treino para ele aparecer aqui.</p>
        </div>
    @else
        {{-- Resumo ----------------------------------------------------------- --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                [$res['sessions'], 'Treinos realizados', $ant['sessions'] > 0 ? 'antes: '.$ant['sessions'] : null],
                [$res['per_week'], 'Treinos por semana', 'média do período'],
                [$res['consistency'].'%', 'Constância', $res['weeks_trained'].' de '.$res['weeks_total'].' semanas com treino'],
                [$res['avg_minutes'] ? $res['avg_minutes'].' min' : '—', 'Duração média', 'por treino'],
                [number_format($res['sets'], 0, ',', '.'), 'Séries feitas', null],
                [$res['volume_kg'].' kg', 'Carga total', 'soma de carga × repetições'],
                [$res['longest_gap_days'] ? $res['longest_gap_days'].' dias' : '—', 'Maior pausa', 'entre dois treinos'],
                [$res['days_since_last'] !== null ? $res['days_since_last'].' dias' : '—', 'Desde o último treino', null],
            ] as [$numero, $rotulo, $dica])
                <div class="card p-4">
                    <p class="eyebrow">{{ $rotulo }}</p>
                    <p class="figure mt-1.5 text-2xl font-medium text-slate-900 dark:text-white">{{ $numero }}</p>
                    @if ($dica) <p class="mt-0.5 text-xs text-slate-400">{{ $dica }}</p> @endif
                </div>
            @endforeach
        </div>

        @if ($relatorio['insights'] !== [])
            <ul class="list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-300">
                @foreach ($relatorio['insights'] as $frase) <li>{{ $frase }}</li> @endforeach
            </ul>
        @endif

        {{-- Frequência ------------------------------------------------------- --}}
        <div class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Treinos por {{ $relatorio['weekly_unit'] }}</h2>
            <div class="mt-3 overflow-x-auto text-slate-400 [&>svg]:h-auto [&>svg]:w-full">{!! GymReportCharts::bars($relatorio['weekly'], 700, 130) !!}</div>
        </div>

        {{-- Destaques -------------------------------------------------------- --}}
        @if ($d['evolution'] || $d['records'] || $d['attention'])
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="card p-5">
                    <p class="eyebrow">Maiores evoluções</p>
                    <ul class="mt-3 space-y-3">
                        @forelse ($d['evolution'] as $l)
                            <li>
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $l['exercise']->name }}</p>
                                <p class="text-xs"><span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $fmtVar($l['change_pct']) }}</span>
                                    <span class="text-slate-500 dark:text-slate-400">({{ $fmtMarca($l, $l['first']) }} → {{ $fmtMarca($l, $l['last']) }})</span></p>
                            </li>
                        @empty
                            <li class="text-xs text-slate-400">Ainda não há exercício com dois treinos ou mais e melhora.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="card p-5">
                    <p class="eyebrow">Recordes pessoais</p>
                    <ul class="mt-3 space-y-3">
                        @forelse ($d['records'] as $l)
                            <li>
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $l['exercise']->name }}</p>
                                <p class="text-xs"><span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $fmtMarca($l, $l['best']) }}</span>
                                    <span class="text-slate-500 dark:text-slate-400">em {{ $l['best']['date']->format('d/m/Y') }}</span></p>
                            </li>
                        @empty
                            <li class="text-xs text-slate-400">Nenhum recorde novo neste período.</li>
                        @endforelse
                    </ul>
                </div>
                <div class="card p-5">
                    <p class="eyebrow">Pedem atenção</p>
                    <ul class="mt-3 space-y-3">
                        @forelse ($d['attention'] as $l)
                            <li>
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $l['exercise']->name }}</p>
                                @if ($l['status'] === 'raise')
                                    <p class="text-xs font-medium text-emerald-700 dark:text-emerald-400">Hora de subir a carga.</p>
                                @else
                                    <p class="text-xs font-medium text-amber-700 dark:text-amber-400">Sem evolução há {{ $l['plateau'] }} treinos.</p>
                                @endif
                            </li>
                        @empty
                            <li class="text-xs text-slate-400">Nenhum exercício parado.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endif

        {{-- Por treino ------------------------------------------------------- --}}
        @foreach ($relatorio['workouts'] as $treino)
            <section class="card overflow-hidden" wire:key="rel-{{ $loop->index }}">
                <div class="border-b border-slate-100 bg-slate-50 px-5 py-3 dark:border-white/10 dark:bg-white/5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                        {{ $treino['name'] }}@if ($treino['focus']) <span class="font-normal text-slate-500 dark:text-slate-400">· {{ $treino['focus'] }}</span>@endif
                    </h2>
                    <p class="text-xs text-slate-400">
                        @if ($treino['plan']) {{ $treino['plan'] }} · @endif
                        {{ $treino['sessions'] }} {{ $treino['sessions'] === 1 ? 'treino' : 'treinos' }} no período · último em {{ $treino['last_done']->format('d/m/Y') }}
                    </p>
                </div>

                <ul class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($treino['exercises'] as $l)
                        <li class="grid items-center gap-x-4 gap-y-2 px-5 py-3 sm:grid-cols-[minmax(0,1.4fr)_9.5rem_minmax(0,1.6fr)_4.5rem]">
                            <div class="min-w-0">
                                <a href="{{ route('health.gym.exercise', $l['exercise']->id) }}" wire:navigate class="block truncate text-sm font-medium text-slate-800 hover:underline dark:text-slate-200">{{ $l['exercise']->name }}</a>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    {{ $l['exercise']->muscle_group->label() }}@if ($l['equipment']) · {{ implode(', ', $l['equipment']) }}@endif
                                </p>
                                @if ($l['status'] === 'raise')
                                    <p class="text-xs font-medium text-emerald-700 dark:text-emerald-400">Hora de subir a carga</p>
                                @elseif ($l['status'] === 'plateau')
                                    <p class="text-xs font-medium text-amber-700 dark:text-amber-400">Sem evolução há {{ $l['plateau'] }} treinos</p>
                                @endif
                            </div>

                            <div class="text-slate-400 [&>svg]:h-auto [&>svg]:w-full">{!! GymReportCharts::spark($l['values'], 150, 38) !!}</div>

                            <div class="text-sm tabular-nums text-slate-700 dark:text-slate-300">
                                <p>
                                    {{ $fmtMarca($l, $l['first']) }} <span class="text-slate-400">→</span> {{ $fmtMarca($l, $l['last']) }}
                                    @if ($l['sessions_count'] >= 2)
                                        <span class="ml-1 text-xs font-semibold {{ $l['change_pct'] !== null && bccomp($l['change_pct'], '0', 1) > 0 ? 'text-emerald-700 dark:text-emerald-400' : (($l['change_pct'] !== null && bccomp($l['change_pct'], '0', 1) < 0) ? 'text-red-700 dark:text-red-400' : 'text-slate-400') }}">{{ $fmtVar($l['change_pct']) }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-slate-400">
                                    {{ $l['first']['date']->format('d/m') }} a {{ $l['last']['date']->format('d/m') }}
                                    · melhor: {{ $fmtMarca($l, $l['best']) }}@if ($l['record']) <span class="font-medium text-emerald-700 dark:text-emerald-400">· recorde</span>@endif
                                </p>
                            </div>

                            <p class="text-xs text-slate-500 sm:text-right dark:text-slate-400">{{ $l['sessions_count'] }} {{ $l['sessions_count'] === 1 ? 'treino' : 'treinos' }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <p class="text-xs text-slate-400">
            "De → para" são a melhor série do primeiro e do último treino do período; a linha mostra um ponto por treino. Recorde = superou tudo o que você tinha feito antes
            do período. Constância = semanas com ao menos um treino. Sem evolução = {{ \App\Services\GymProgressService::PLATEAU_AFTER }} treinos seguidos sem superar a melhor marca (na mesma máquina).
        </p>
    @endif
</div>
