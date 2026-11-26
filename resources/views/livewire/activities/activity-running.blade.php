@php
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $pilula = fn (bool $on) => 'rounded-full border px-3 py-1.5 text-sm '.($on ? 'border-saude-800 bg-saude-800 text-white dark:border-saude-200 dark:bg-saude-200 dark:text-slate-900' : 'border-slate-200 text-slate-700 dark:border-white/10 dark:text-slate-300');
@endphp

<div class="space-y-5">
    <x-activity-header title="Caminhada e corrida" subtitle="Um programa que parte do que você consegue hoje, mesmo que já tenha corrido muito." />

    @if (session('status'))
        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    <p class="text-xs text-slate-400">É uma orientação geral, não a prescrição de um profissional. Com dor, lesão ou condição de saúde, converse antes com um médico ou profissional de educação física.</p>

    @if ($bloqueio)
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-300" data-recomenda-aguardar>
            <p>{{ $bloqueio }}</p>
            @if ($programa?->chose_despite_limitation_at)
                <p class="mt-1 font-medium">Você escolheu começar assim mesmo em {{ $programa->chose_despite_limitation_at->format('d/m/Y') }}.</p>
            @elseif (! $programa)
                <label class="mt-2 flex items-start gap-2 font-medium"><input type="checkbox" wire:model.live="anyway" class="mt-0.5 h-4 w-4 rounded border-slate-300"> Montar mesmo assim. O Cerne guarda que a escolha foi minha.</label>
            @endif
        </div>
    @endif

    @error('form') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300" data-form-error>{{ $message }}</p> @enderror

    @if (! $programa)
        @if (! $bloqueio || $anyway)
            <form wire:submit="start" class="card max-w-2xl space-y-4 p-5">
                <div>
                    <p class="{{ $campo }}">Quanto trote confortável, sem dor, você faz hoje?</p>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @foreach ($testes as $v => $rotulo)
                            <button type="button" wire:click="$set('test', {{ $v }})" aria-pressed="{{ $test === $v ? 'true' : 'false' }}" class="{{ $pilula($test === $v) }}">{{ $rotulo }}</button>
                        @endforeach
                    </div>
                </div>
                <div>
                    <p class="{{ $campo }}">Vezes por semana</p>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @foreach ([1, 2, 3] as $v)
                            <button type="button" wire:click="$set('times', {{ $v }})" aria-pressed="{{ $times === $v ? 'true' : 'false' }}" class="{{ $pilula($times === $v) }}">{{ $v }}</button>
                        @endforeach
                    </div>
                </div>
                <fieldset>
                    <legend class="{{ $campo }}">Em quais dias</legend>
                    <div class="mt-1.5 flex flex-wrap gap-3">
                        @foreach ($dias as $d => $nomeDoDia)
                            <label class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="weekdays" value="{{ $d }}" class="h-4 w-4 rounded border-slate-300"> {{ $nomeDoDia }}</label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-slate-400">Já vêm sugeridos dias que não caem depois das pernas nem ficam colados. Você troca à vontade.</p>
                </fieldset>
                <x-scale-input model="painToday" :value="$painToday" label="Dor hoje, de 0 a 10" hint="É a referência: se a dor passar disso em uma semana, o programa volta um passo." />
                <x-form-consequence :lines="$consequencia" />
                <x-form-errors />
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Montar programa</button>
            </form>
        @endif
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="space-y-4">
                @if ($fechar)
                    <div class="card space-y-3 border border-saude-800 p-5 dark:border-saude-200" data-fechar-semana>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Fechar a semana de {{ $fechar['week_start']->format('d/m') }}</h2>
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $fechar['done'] }} de {{ $fechar['expected'] }} sessões · {{ $fechar['pain'] !== null ? 'maior dor: '.$fechar['pain'] : 'sem nota de dor' }}. {{ $fechar['reason'] }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($resultados as $valor => $rotulo)
                                <button type="button" wire:click="close('{{ $valor }}')" class="{{ $valor === $fechar['suggestion'] ? 'btn-primary' : 'btn-secondary' }} px-3 py-1.5 text-sm">{{ $rotulo }}{{ $valor === $fechar['suggestion'] ? ' (sugerido)' : '' }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="card space-y-2 p-5">
                    <p class="eyebrow">Esta semana</p>
                    <p class="text-sm text-slate-700 dark:text-slate-300">{{ $semanaAtual['done'] }} de {{ $semanaAtual['expected'] }} sessões feitas. Dias: {{ mb_strtolower(implode(', ', array_map(fn ($d) => $dias[(int) $d], (array) $programa->weekdays))) }}.</p>
                    <p class="text-xs text-slate-400">Confirme cada sessão em Minha semana, com esforço e dor. É com isso que o Cerne sugere avançar.</p>
                    <a href="{{ route('health.activities.index') }}" wire:navigate class="btn-secondary inline-flex px-3 py-1.5 text-sm">Abrir Minha semana</a>
                </div>
                @if ($historico->isNotEmpty())
                    <div class="card p-5">
                        <p class="eyebrow">Semanas fechadas</p>
                        <ul class="mt-1 divide-y divide-slate-100 text-sm text-slate-600 dark:divide-white/10 dark:text-slate-300">
                            @foreach ($historico as $s)
                                <li class="py-1.5" wire:key="semana-{{ $s->id }}">{{ $s->week_start->format('d/m') }}: {{ ['advance' => 'avançou', 'repeat' => 'repetiu', 'back' => 'voltou um passo'][$s->outcome] ?? $s->outcome }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <button type="button" wire:click="stop" wire:confirm="Encerrar o programa? O diário continua." class="text-xs text-slate-400 hover:text-red-700">Encerrar o programa</button>
            </div>
            <div class="card min-w-0 p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Seu caminho</h2>
                <p class="text-xs text-slate-400">Cada sessão começa com 5 min de caminhada.</p>
                <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-sm">
                    @foreach ($passos as $i => $texto)
                        <li @class(['text-slate-400' => $i < $programa->step, 'font-semibold text-slate-900 dark:text-white' => $i === $programa->step, 'text-slate-600 dark:text-slate-300' => $i > $programa->step])>{{ $texto }}@if ($i === $programa->step) <span class="ml-1 rounded-full bg-saude-200/40 px-2 py-0.5 text-xs font-medium text-saude-800 dark:bg-saude-800/40 dark:text-saude-200">Você está aqui</span>@endif</li>
                    @endforeach
                </ol>
                <p class="mt-3 text-xs text-slate-400">Avança só com a semana completa e sem aumento de dor. Falta repete o passo. Dor maior volta um. Se a dor continuar, procure o profissional.</p>
            </div>
        </div>
    @endif
</div>
