@use('App\Enums\GymMeasureType')

<div
    class="space-y-5 pb-24"
    x-data="gymSession(@js($sessionId))"
    x-on:click="unlockAudio()"
    x-on:gym-rest-start.window="startRest($event.detail.seconds)"
    x-on:gym-rest-cancel.window="cancelRest()"
>

    <div>
        <a href="{{ route('health.gym.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Academia</a>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $sessao->workout->name }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            @if ($sessao->workout->focus) {{ $sessao->workout->focus }} · @endif
            {{ $emAndamento ? 'em andamento desde '.$sessao->started_at?->format('H:i') : 'finalizado em '.$sessao->finished_at->format('d/m/Y \à\s H:i') }}
        </p>
    </div>

    @error('session')
        <p class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p>
    @enderror

    @if ($emAndamento)
        {{-- Interruptores globais da conta: valem pra todo treino, não só este. --}}
        <details class="card px-5 py-3">
            <summary class="cursor-pointer text-sm font-medium text-slate-700 dark:text-slate-300">Ajustes do treino</summary>
            <div class="mt-3 space-y-3">
                @foreach ([
                    ['keepAwake', 'Manter a tela ligada', 'Evita que o celular bloqueie durante o treino.'],
                    ['vibrate', 'Vibrar quando a pausa acabar', null],
                    ['sound', 'Tocar som quando a pausa acabar', null],
                ] as [$campo, $titulo, $ajuda])
                    <label class="flex items-center justify-between gap-4">
                        <span class="min-w-0">
                            <span class="block text-sm text-slate-800 dark:text-slate-200">{{ $titulo }}</span>
                            @if ($ajuda) <span class="block text-xs text-slate-400">{{ $ajuda }}</span> @endif
                            @if ($campo === 'vibrate')
                                <span x-show="!canVibrate" x-cloak class="block text-xs text-slate-400">Indisponível neste aparelho (iPhone não permite vibração pelo navegador).</span>
                            @endif
                        </span>
                        <span class="relative inline-flex shrink-0 items-center">
                            <input type="checkbox" wire:model.live="{{ $campo }}" class="peer sr-only"
                                @if ($campo === 'vibrate') x-bind:disabled="!canVibrate" @endif>
                            <span class="block h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-saude-800 peer-disabled:opacity-40 peer-focus-visible:ring-2 peer-focus-visible:ring-saude-800/50 dark:bg-slate-600 after:absolute after:left-0.5 after:top-0.5 after:h-5 after:w-5 after:rounded-full after:bg-white after:transition after:content-[''] peer-checked:after:translate-x-5"></span>
                        </span>
                    </label>
                @endforeach
            </div>
        </details>
    @endif

    @foreach ($itens as $item)
        @php
            $exercicio = $item->exercise;
            $feitas = $series[$exercicio->id] ?? collect();
            $qtd = $feitas->count();
            $emTempo = $exercicio->measure_type->usesStopwatch();
            $proxima = $qtd + 1;
            $mostrarForm = $emAndamento && ($qtd < $item->target_sets || ($extra[$item->id] ?? false));
        @endphp

        <section class="card p-5" wire:key="ex-{{ $item->id }}">
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-start gap-3">
                    <x-gym.exercise-photo :exercise="$exercicio" />
                    <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $exercicio->name }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        {{ $exercicio->muscle_group->label() }} · meta {{ $item->target_sets }}×
                        @if ($item->target_duration_seconds)
                            {{ $item->target_duration_seconds }}s
                        @elseif ($item->target_reps_min && $item->target_reps_max && $item->target_reps_min !== $item->target_reps_max)
                            {{ $item->target_reps_min }}–{{ $item->target_reps_max }}
                        @elseif ($item->target_reps_min || $item->target_reps_max)
                            {{ $item->target_reps_min ?? $item->target_reps_max }}
                        @else
                            livre
                        @endif
                        @if ($item->rest_seconds) · pausa {{ $item->rest_seconds }}s @endif
                    </p>
                    @if ($item->notes)
                        <p class="mt-0.5 text-xs text-slate-400">{{ $item->notes }}</p>
                    @endif
                    @isset($lastLabel[$item->id])
                        <p class="mt-1 text-xs text-saude-800 dark:text-saude-200">Última vez: {{ $lastLabel[$item->id] }}</p>
                    @endisset
                    @isset($hint[$item->id])
                        <p class="mt-1 text-xs {{ $hint[$item->id]['type'] === 'raise' ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400' }}">{{ $hint[$item->id]['message'] }}</p>
                    @endisset
                    </div>
                </div>
                <div class="flex shrink-0 flex-col items-end gap-1">
                    <span class="text-xs tabular-nums {{ $qtd >= $item->target_sets ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400' }}">
                        {{ $qtd }}/{{ $item->target_sets }}
                    </span>
                    {{-- Só antes da primeira série: depois dela o exercício já tem registro neste treino. --}}
                    @if ($emAndamento && $qtd === 0)
                        <button type="button" wire:click="openSwap('{{ $item->id }}')" class="btn-ghost px-2 py-1 text-xs">Trocar</button>
                    @endif
                </div>
            </div>

            @if ($feitas->isNotEmpty())
                <ul class="mt-3 divide-y divide-slate-100 text-sm dark:divide-white/10">
                    @foreach ($feitas as $serie)
                        <li class="flex items-center justify-between gap-3 py-2" wire:key="set-{{ $serie->id }}">
                            <span class="text-slate-500 dark:text-slate-400">Série {{ $serie->set_number }}</span>
                            <span class="text-right tabular-nums text-slate-800 dark:text-slate-200">
                                @if ($serie->load_value !== null)
                                    {{ rtrim(rtrim($serie->load_value, '0'), '.') }} kg
                                    <span class="text-xs text-slate-400">{{ mb_strtolower($serie->load_mode?->label() ?? '') }}</span>
                                    ×
                                @elseif ($serie->load_mode)
                                    <span class="text-xs text-slate-400">{{ mb_strtolower($serie->load_mode->label()) }}</span>
                                @endif
                                @if ($serie->reps) {{ $serie->reps }} reps @endif
                                @if ($serie->duration_seconds) {{ intdiv($serie->duration_seconds, 60) }}:{{ str_pad((string) ($serie->duration_seconds % 60), 2, '0', STR_PAD_LEFT) }} @endif
                                @if ($serie->distance_meters) · {{ $serie->distance_meters }} m @endif
                                @if ($serie->equipment) <span class="text-xs text-slate-400">· {{ $serie->equipment->name }}</span> @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
                @if ($emAndamento)
                    <button type="button" wire:click="undoSet('{{ $item->id }}')" class="mt-1 text-xs text-slate-400 hover:underline">Desfazer última série</button>
                @endif
            @endif

            @if ($mostrarForm)
                <div class="mt-4 rounded-xl bg-slate-50 p-4 dark:bg-white/5">
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Série {{ $proxima }}</p>

                    <div class="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @if (! $emTempo)
                            <div>
                                <label class="block text-xs text-slate-500 dark:text-slate-400">Carga (kg)</label>
                                <input type="text" inputmode="decimal" wire:model="draft.{{ $item->id }}.load" class="input mt-1" placeholder="0">
                                @error("draft.$item->id.load") <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs text-slate-500 dark:text-slate-400">Como pesa</label>
                                <select wire:model="draft.{{ $item->id }}.mode" class="select mt-1 w-full">
                                    @foreach ($modos as $valor => $rotulo)
                                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-slate-500 dark:text-slate-400">Repetições</label>
                                <input type="number" inputmode="numeric" min="1" wire:model="draft.{{ $item->id }}.reps" class="input mt-1">
                                @error("draft.$item->id.reps") <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @elseif ($exercicio->measure_type === GymMeasureType::DistanceDuration)
                            <div>
                                <label class="block text-xs text-slate-500 dark:text-slate-400">Distância (m)</label>
                                <input type="number" inputmode="numeric" min="0" wire:model="draft.{{ $item->id }}.distance" class="input mt-1">
                            </div>
                        @endif

                        @if ($equipamentos->isNotEmpty())
                            <div>
                                <label class="block text-xs text-slate-500 dark:text-slate-400">Equipamento</label>
                                <select wire:model="draft.{{ $item->id }}.equipment" class="select mt-1 w-full">
                                    <option value="">—</option>
                                    @foreach ($equipamentos as $equipamento)
                                        <option value="{{ $equipamento->id }}">{{ $equipamento->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>

                    @if (! $emTempo)
                        <button type="button" wire:click="completeSet('{{ $item->id }}', {{ $proxima }})"
                            wire:loading.attr="disabled" wire:target="completeSet"
                            class="btn-primary mt-4 w-full disabled:opacity-60">
                            Concluir série {{ $proxima }}
                        </button>
                    @else
                        {{-- Exercício em tempo: cronômetro (iniciar → concluir), sem contagem regressiva de pausa. --}}
                        <div x-data="gymStopwatch(@js($sessionId), @js($item->id))" class="mt-4">
                            <p class="text-center font-display text-4xl tabular-nums text-slate-900 dark:text-white" x-text="clock(elapsed)">0:00</p>
                            <div class="mt-3 flex gap-2">
                                <button type="button" x-show="!running" x-on:click="start()" class="btn-primary w-full">Iniciar cronômetro</button>
                                <button type="button" x-show="running" x-cloak x-on:click="stop({{ $proxima }})" class="btn-primary w-full">Concluir série {{ $proxima }}</button>
                                <button type="button" x-show="running" x-cloak x-on:click="reset()" class="btn-ghost px-3 text-xs">Zerar</button>
                            </div>
                        </div>
                    @endif
                </div>
            @elseif ($emAndamento)
                <div class="mt-3 flex items-center justify-between">
                    <p class="text-xs text-emerald-700 dark:text-emerald-400">Meta cumprida</p>
                    <button type="button" wire:click="addExtraSet('{{ $item->id }}')" class="btn-ghost px-2 py-1 text-xs">+ Série extra</button>
                </div>
            @endif
        </section>
    @endforeach

    {{-- Trocar exercício: para as próximas vezes (muda o treino) ou só por hoje. --}}
    @if ($emAndamento)
        <x-modal wire-model="showSwap" max-width="lg">
            @if ($swapItem)
                <div class="space-y-4">
                    <div class="flex items-baseline justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Trocar exercício</h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                Saindo: {{ $swapItem->exercise->name }} · {{ $swapItem->exercise->muscle_group->label() }}. Séries, repetições e pausa continuam.
                            </p>
                        </div>
                        <button type="button" wire:click="closeSwap" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                    </div>

                    @error('swap') <p class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p> @enderror

                    <fieldset class="grid gap-2 sm:grid-cols-2">
                        <legend class="sr-only">Até quando vale a troca</legend>
                        <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-slate-200 p-3 text-xs dark:border-white/10 {{ $swapScope === 'plan' ? 'ring-2 ring-saude-800/60' : '' }}">
                            <input type="radio" wire:model.live="swapScope" value="plan" class="mt-0.5">
                            <span><span class="block text-sm font-medium text-slate-800 dark:text-slate-200">Salvar no treino</span><span class="text-slate-500 dark:text-slate-400">Vale também nas próximas vezes.</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-slate-200 p-3 text-xs dark:border-white/10 {{ $swapScope === 'today' ? 'ring-2 ring-saude-800/60' : '' }}">
                            <input type="radio" wire:model.live="swapScope" value="today" class="mt-0.5">
                            <span><span class="block text-sm font-medium text-slate-800 dark:text-slate-200">Só por hoje</span><span class="text-slate-500 dark:text-slate-400">O treino volta ao normal depois.</span></span>
                        </label>
                    </fieldset>

                    <div class="flex flex-wrap items-center gap-3">
                        <input type="search" wire:model.live.debounce.250ms="swapSearch" class="input min-w-0 flex-1" placeholder="Buscar por nome ou equipamento">
                        <label class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                            <input type="checkbox" wire:model.live="swapAllGroups" class="h-4 w-4 rounded border-slate-300">
                            Todos os grupos
                        </label>
                    </div>

                    <div class="max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-white/10 dark:border-white/10">
                        @forelse ($swapOptions as $opcao)
                            <button type="button" wire:click="swapTo('{{ $opcao['key'] }}')" wire:key="troca-{{ $opcao['key'] }}"
                                class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-slate-50 dark:hover:bg-white/5">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm text-slate-800 dark:text-slate-200">{{ $opcao['name'] }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $opcao['group'] }}@if ($opcao['equipment']) · {{ $opcao['equipment'] }}@endif</span>
                                </span>
                                <span class="shrink-0 text-xs {{ $opcao['own'] ? 'text-saude-800 dark:text-saude-200' : 'text-slate-400' }}">{{ $opcao['own'] ? 'Você já usa' : 'Catálogo' }}</span>
                            </button>
                        @empty
                            <p class="px-3 py-6 text-center text-xs text-slate-400">Nenhum exercício bate com a busca.</p>
                        @endforelse
                    </div>

                    <form wire:submit="swapToCustom" class="flex flex-wrap items-end gap-2">
                        <div class="min-w-0 flex-1">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Não achou? Digite o nome</label>
                            <input type="text" wire:model="swapCustomName" class="input mt-1.5" placeholder="Ex.: Supino na máquina Smith">
                            @error('swapCustomName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn-secondary">Usar este</button>
                    </form>
                </div>
            @endif
        </x-modal>
    @endif

    @if ($emAndamento)
        <div class="flex flex-col gap-2 sm:flex-row">
            <button type="button" wire:click="finish" class="btn-primary sm:flex-1">
                Finalizar treino <span class="opacity-75">({{ $totalSeries }} {{ $totalSeries === 1 ? 'série' : 'séries' }})</span>
            </button>
            <button type="button" wire:click="discard" wire:confirm="Descartar este treino e todas as séries já registradas?" class="btn-ghost text-red-700 dark:text-red-400">
                Descartar treino
            </button>
        </div>
    @else
        <a href="{{ route('health.gym.index') }}" wire:navigate class="btn-secondary inline-flex">Voltar à Academia</a>
    @endif

    {{-- Pausa entre séries: flutua acima da barra do celular pra ficar à vista durante todo o treino. --}}
    <div
        x-show="remaining > 0 || ended"
        x-cloak
        class="fixed inset-x-3 bottom-20 z-40 lg:inset-x-auto lg:bottom-6 lg:right-6 lg:w-80"
    >
        <div class="card overflow-hidden shadow-lg" x-bind:class="ended ? 'ring-2 ring-emerald-500' : ''">
            <div x-show="remaining > 0" class="p-4">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Pausa</p>
                    <p class="font-display text-3xl tabular-nums text-slate-900 dark:text-white" x-text="clock(remaining)"></p>
                </div>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                    <div class="h-full rounded-full bg-saude-800 dark:bg-saude-200 transition-[width] duration-300" x-bind:style="'width:' + Math.min(100, (remaining / Math.max(1, restTotal)) * 100) + '%'"></div>
                </div>
                <div class="mt-3 flex gap-2">
                    <button type="button" x-on:click="adjustRest(15)" class="btn-secondary flex-1 text-xs">+15 s</button>
                    <button type="button" x-on:click="cancelRest()" class="btn-ghost flex-1 text-xs">Pular pausa</button>
                </div>
            </div>
            <div x-show="remaining <= 0 && ended" class="p-4 text-center">
                <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">Pausa acabou — próxima série!</p>
            </div>
        </div>
    </div>
</div>
