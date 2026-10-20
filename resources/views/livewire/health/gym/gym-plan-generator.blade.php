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

    @if ($step === 'review' && $revisao)
        {{-- Passo 0: como foi a fase e se os objetivos continuam ------------------ --}}
        @php
            $r = $revisao['review'];
            $tom = match ($r['status']) {
                'due' => 'border-l-amber-500',
                'soon' => 'border-l-sky-500',
                'slow' => 'border-l-slate-400',
                default => 'border-l-emerald-500',
            };
        @endphp

        <div class="card border-l-4 {{ $tom }} space-y-3 p-5">
            <p class="eyebrow">Sua fase atual</p>
            <h2 class="text-lg font-medium text-slate-900 dark:text-white">{{ $plano->name }}</h2>
            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $r['headline'] }}</p>
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ $r['message'] }}</p>

            <dl class="grid grid-cols-3 gap-3 pt-1 text-center">
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Semanas na fase</dt>
                    <dd class="figure mt-1 text-xl text-slate-900 dark:text-white">{{ $r['weeks'] }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Treinos feitos</dt>
                    <dd class="figure mt-1 text-xl text-slate-900 dark:text-white">{{ $r['sessions'] }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3 dark:bg-white/5">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Exercícios que melhoraram</dt>
                    <dd class="figure mt-1 text-xl text-slate-900 dark:text-white">{{ $revisao['improved'] }}@if ($revisao['measured'] > 0)<span class="text-sm text-slate-400"> de {{ $revisao['measured'] }}</span>@endif</dd>
                </div>
            </dl>

            @if ($revisao['top'])
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Maiores evoluções:
                    @foreach ($revisao['top'] as $t) {{ $t['exercise']->name }} ({{ str_replace('.', ',', $t['change_pct']) }}%){{ $loop->last ? '' : ', ' }}@endforeach.
                </p>
            @endif

            <a href="{{ route('health.gym.report', ['periodo' => 'fase']) }}" wire:navigate class="inline-block text-xs underline text-slate-500 hover:text-slate-700 dark:text-slate-400">Ver o relatório desta fase</a>
        </div>

        @if ($plano->goal)
            <div class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Seus objetivos continuam os mesmos?</h2>
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Objetivo</dt><dd class="text-slate-800 dark:text-slate-200">{{ $plano->goal->label() }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Nível</dt><dd class="text-slate-800 dark:text-slate-200">{{ $plano->level?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Dias por semana</dt><dd class="text-slate-800 dark:text-slate-200">{{ $plano->days_per_week ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Tempo por treino</dt><dd class="text-slate-800 dark:text-slate-200">{{ $plano->session_minutes ? $plano->session_minutes.' minutos' : '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Onde treina</dt><dd class="text-slate-800 dark:text-slate-200">{{ $plano->equipment_access?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Regiões protegidas</dt><dd class="text-slate-800 dark:text-slate-200">{{ $plano->restrictions ? implode(', ', array_map(fn ($v) => $restricoes[$v] ?? $v, $plano->restrictions)) : 'nenhuma' }}</dd></div>
                </dl>

                @if ($revisao['level_suggestion'])
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm dark:border-sky-500/30 dark:bg-sky-500/10">
                        <input type="checkbox" wire:model="upgradeLevel" class="mt-1 h-4 w-4 rounded border-slate-300">
                        <span class="text-slate-700 dark:text-slate-200">
                            Você treina há bastante tempo. Quer passar para <strong>{{ $revisao['level_suggestion']->label() }}</strong> neste treino novo?
                        </span>
                    </label>
                @endif
            </div>
        @else
            <div class="card space-y-2 p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Qual é o seu objetivo agora?</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">Esta fase foi montada à mão, então o Cerne não guardou o objetivo dela. Responda as perguntas a seguir para montar o treino novo.</p>
            </div>
        @endif

        <fieldset class="card space-y-3 p-5">
            <legend class="text-sm font-semibold text-slate-900 dark:text-white">E os exercícios do treino novo?</legend>
            @foreach (['vary' => ['Variar', 'Evita repetir os exercícios desta fase quando há alternativa. Mudar o estímulo é parte do motivo de trocar.'], 'keep' => ['Manter os que evoluíram', 'Fica com os exercícios que melhoraram nesta fase e troca os demais.'], 'free' => ['Tanto faz', 'Escolhe só pelo seu pedido, sem olhar a fase anterior.']] as $valor => [$titulo, $ajuda])
                <label class="{{ $card }} {{ $variety === $valor ? 'ring-2 ring-saude-800/60' : '' }}">
                    <input type="radio" wire:model.live="variety" value="{{ $valor }}" class="mt-1">
                    <span><span class="block font-medium text-slate-800 dark:text-slate-200">{{ $titulo }}</span><span class="block text-xs text-slate-500 dark:text-slate-400">{{ $ajuda }}</span></span>
                </label>
            @endforeach
        </fieldset>

        <div class="flex flex-wrap gap-2">
            @if ($plano->goal)
                <button type="button" wire:click="keepGoals" wire:loading.attr="disabled" class="btn-primary">Sim, manter e montar o treino novo</button>
                <button type="button" wire:click="changeGoals" class="btn-secondary">Mudou, quero ajustar</button>
            @else
                <button type="button" wire:click="changeGoals" class="btn-primary">Responder e montar o treino novo</button>
            @endif
        </div>
    @elseif ($draft === null)
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

            @if ($temPlanoAtivo)
                <fieldset class="card space-y-3 p-5">
                    <legend class="text-sm font-semibold text-slate-900 dark:text-white">E os exercícios, em relação à fase atual?</legend>
                    @foreach (['vary' => 'Variar: evitar repetir os da fase atual', 'keep' => 'Manter os que evoluíram e variar o resto', 'free' => 'Tanto faz'] as $valor => $rotulo)
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="radio" wire:model="variety" value="{{ $valor }}"> {{ $rotulo }}
                        </label>
                    @endforeach
                </fieldset>
            @endif

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
