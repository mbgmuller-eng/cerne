@php
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $card = 'flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm dark:border-white/10';
@endphp

<div class="space-y-6">
    <div>
        <a href="{{ route('health.gym.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Academia</a>
        <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Treino automático</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Responda algumas perguntas e o Cerne monta um treino com os exercícios do catálogo. Você revisa, troca o que quiser e só depois salva.
        </p>
    </div>

    @if ($draft === null)
        {{-- Passo 1: o pedido -------------------------------------------------- --}}
        <form wire:submit="generate" class="space-y-6">
            <fieldset class="card space-y-3 p-5">
                <legend class="text-sm font-semibold text-slate-900 dark:text-white">Qual é o seu objetivo?</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($objetivos as $o)
                        <label class="{{ $card }} {{ $goal === $o->value ? 'ring-2 ring-saude-800/60' : '' }}">
                            <input type="radio" wire:model.live="goal" value="{{ $o->value }}" class="mt-1">
                            <span>
                                <span class="block font-medium text-slate-800 dark:text-slate-200">{{ $o->label() }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $o->hint() }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('goal') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </fieldset>

            <div class="card grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <label class="{{ $campo }}">Seu nível</label>
                    <select wire:model="level" class="select mt-1.5 w-full">
                        @foreach ($niveis as $valor => $rotulo) <option value="{{ $valor }}">{{ $rotulo }}</option> @endforeach
                    </select>
                    @error('level') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Onde você treina</label>
                    <select wire:model="access" class="select mt-1.5 w-full">
                        @foreach ($acessos as $valor => $rotulo) <option value="{{ $valor }}">{{ $rotulo }}</option> @endforeach
                    </select>
                    @error('access') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Dias por semana</label>
                    <select wire:model="days" class="select mt-1.5 w-full">
                        @foreach (range(2, 6) as $n) <option value="{{ $n }}">{{ $n }} dias</option> @endforeach
                    </select>
                    @error('days') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Tempo por treino</label>
                    <select wire:model="minutes" class="select mt-1.5 w-full">
                        @foreach ($minutosOpcoes as $m) <option value="{{ $m }}">{{ $m }} minutos</option> @endforeach
                    </select>
                    @error('minutes') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <fieldset class="card space-y-3 p-5">
                <legend class="text-sm font-semibold text-slate-900 dark:text-white">Quer proteger alguma região? <span class="font-normal text-slate-400">(opcional)</span></legend>
                <p class="text-xs text-slate-500 dark:text-slate-400">O treino deixa de fora os exercícios que mais exigem a região marcada.</p>
                <div class="flex flex-wrap gap-3">
                    @foreach ($restricoes as $valor => $rotulo)
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="checkbox" wire:model="restrictions" value="{{ $valor }}" class="h-4 w-4 rounded border-slate-300">
                            {{ $rotulo }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <p class="rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500 dark:bg-white/5 dark:text-slate-400">
                É uma orientação geral, não a prescrição de um profissional. Com dor, lesão ou condição de saúde, converse com um médico ou
                profissional de educação física antes de treinar.
            </p>

            <button type="submit" class="btn-primary w-full sm:w-auto">Montar meu treino</button>
        </form>
    @else
        {{-- Passo 2: revisar e salvar ---------------------------------------- --}}
        <div class="card space-y-3 p-5">
            <div>
                <label class="{{ $campo }}">Nome da fase</label>
                <input type="text" wire:model="draft.name" class="input mt-1.5" maxlength="80">
                @error('draft.name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ $draft['summary'] }}</p>

            @foreach ($draft['warnings'] as $aviso)
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-300">{{ $aviso }}</p>
            @endforeach
        </div>

        @error('draft') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p> @enderror

        @foreach ($draft['workouts'] as $w => $treino)
            <section class="card p-5" wire:key="rascunho-{{ $w }}">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $treino['name'] }} <span class="font-normal text-slate-500 dark:text-slate-400">· {{ $treino['focus'] }}</span></h2>

                @if ($treino['exercises'] === [])
                    <p class="mt-3 text-xs text-slate-400">Sem exercícios neste dia.</p>
                @else
                    <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($treino['exercises'] as $i => $e)
                            <li class="flex items-start justify-between gap-3 py-3" wire:key="rascunho-{{ $w }}-{{ $i }}-{{ $e['catalog_id'] }}">
                                <div class="min-w-0">
                                    <p class="text-sm text-slate-800 dark:text-slate-200">{{ $e['name'] }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                        {{ $e['group'] }}@if ($e['equipment']) · {{ $e['equipment'] }}@endif
                                        · {{ $e['sets'] }}×
                                        @if ($e['duration'])
                                            {{ intdiv($e['duration'], 60) > 0 && $e['duration'] % 60 === 0 ? ($e['duration'] / 60).' min' : $e['duration'].'s' }}
                                        @elseif ($e['reps_min'] && $e['reps_max'])
                                            {{ $e['reps_min'] }}–{{ $e['reps_max'] }}
                                        @endif
                                        @if ($e['rest']) · pausa {{ $e['rest'] }}s @endif
                                    </p>
                                    @if ($e['notes'])
                                        <p class="mt-0.5 text-xs text-slate-400">{{ $e['notes'] }}</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 items-center gap-1">
                                    <button type="button" wire:click="openSwap({{ $w }}, {{ $i }})" class="btn-ghost px-2 py-1 text-xs">Trocar</button>
                                    <button type="button" wire:click="removeExercise({{ $w }}, {{ $i }})" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Remover</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach

        @if ($temPlanoAtivo)
            <p class="rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500 dark:bg-white/5 dark:text-slate-400">
                Ao salvar, a fase atual é encerrada e fica no histórico. Esta passa a ser a ativa.
            </p>
        @endif

        <p class="text-xs text-slate-400">Depois de salvar, você ainda pode trocar exercícios, ajustar séries e repetições no plano e durante o treino.</p>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="save" wire:loading.attr="disabled" class="btn-primary">Salvar como nova fase</button>
            <button type="button" wire:click="regenerate" class="btn-secondary">Sortear outras opções</button>
            <button type="button" wire:click="backToForm" class="btn-ghost">Mudar o pedido</button>
        </div>

        {{-- Trocar um exercício do rascunho -------------------------------------- --}}
        <x-modal wire-model="showSwap" max-width="lg">
            @if ($swapLinha)
                <div class="space-y-4">
                    <div class="flex items-baseline justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Trocar exercício</h2>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Saindo: {{ $swapLinha['name'] }} · {{ $swapLinha['group'] }}. As opções já respeitam o que você tem e as restrições.</p>
                        </div>
                        <button type="button" wire:click="closeSwap" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                    </div>

                    @error('swap') <p class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p> @enderror

                    <div class="flex flex-wrap items-center gap-3">
                        <input type="search" wire:model.live.debounce.250ms="swapSearch" class="input min-w-0 flex-1" placeholder="Buscar por nome ou equipamento">
                        <label class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                            <input type="checkbox" wire:model.live="swapAllGroups" class="h-4 w-4 rounded border-slate-300">
                            Todos os grupos
                        </label>
                    </div>

                    <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-white/10 dark:border-white/10">
                        @forelse ($swapOptions as $opcao)
                            <button type="button" wire:click="swapTo('{{ $opcao['id'] }}')" wire:key="troca-{{ $opcao['id'] }}"
                                class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-slate-50 dark:hover:bg-white/5">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm text-slate-800 dark:text-slate-200">{{ $opcao['name'] }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $opcao['group'] }}@if ($opcao['equipment']) · {{ $opcao['equipment'] }}@endif</span>
                                </span>
                            </button>
                        @empty
                            <p class="px-3 py-6 text-center text-xs text-slate-400">Nenhum exercício bate com a busca.</p>
                        @endforelse
                    </div>
                </div>
            @endif
        </x-modal>
    @endif
</div>
