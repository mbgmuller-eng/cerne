@php
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $pilula = fn (bool $on) => 'rounded-full border px-3 py-1.5 text-sm '.($on ? 'border-saude-800 bg-saude-800 text-white dark:border-saude-200 dark:bg-saude-200 dark:text-slate-900' : 'border-slate-200 text-slate-700 dark:border-white/10 dark:text-slate-300');
    $listaDeDias = fn (array $d) => mb_strtolower(implode(', ', array_map(fn ($i) => $nomesDosDias[(int) $i] ?? '', $d)));
@endphp

<div class="space-y-5">
    <x-activity-header title="Plano da semana" subtitle="Musculação, aeróbico e descanso, partindo do que você já faz." />

    @if (session('status'))
        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    <div class="space-y-1">
        <p class="text-xs text-slate-400">É uma orientação geral, não a prescrição de um profissional. A referência de 150 a 300 minutos de aeróbico por semana é a da Organização Mundial da Saúde para adultos.</p>
        <x-rule-status key="week_plan" />
    </div>

    @if ($porta)
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-300" data-prontidao>
            <p>{{ $porta }}</p>
            <a href="{{ route('health.activities.readiness') }}" wire:navigate class="btn-secondary mt-2 inline-flex px-3 py-1.5 text-sm">Abrir as perguntas de prontidão</a>
        </div>
    @elseif ($leve)
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-300" data-plano-leve>
            <p>Sem liberação médica, o plano é leve: sem aumento de minutos, sem mudança de séries e repetições, em ritmo que deixe conversar.</p>
            <a href="{{ route('health.activities.readiness') }}" wire:navigate class="mt-1 inline-block font-medium underline">Atualizar as respostas</a>
        </div>
    @endif

    @error('form') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300" data-form-error>{{ $message }}</p> @enderror

    @if ($vigente)
        @php $p = $vigente['plan']; @endphp
        <section class="card space-y-4 p-5" data-plano-vigente>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Plano em vigor: {{ mb_strtolower($objetivos[$p->goal] ?? $p->goal) }}{{ $p->is_light ? ' (leve)' : '' }}</h2>
                    <p class="text-xs text-slate-400">Desde {{ $p->started_on->format('d/m/Y') }}. Musculação: {{ $p->strength_days !== [] ? $listaDeDias((array) $p->strength_days) : 'nenhum dia' }}. Aeróbico: {{ $p->aerobic_days !== [] ? $listaDeDias((array) $p->aerobic_days) : 'no fim da musculação' }}.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if (! $editing && ! $porta)
                        <button type="button" wire:click="edit" class="btn-secondary px-3 py-1.5 text-sm">Refazer o plano</button>
                    @endif
                    <button type="button" wire:click="undo" wire:confirm="Desfazer a atualização? As séries, os dias e os exercícios pausados por ela voltam a ser como antes. Se uma limitação ainda valer, a revisão do treino volta a sugerir a pausa." class="btn-secondary px-3 py-1.5 text-sm">Desfazer atualização</button>
                </div>
            </div>

            @if ($vigente['stale'])
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-300">As respostas de prontidão mudaram depois deste plano. Use "Refazer o plano" para ele acompanhar.</p>
            @endif

            <div>
                <p class="text-sm text-slate-700 dark:text-slate-300">Esta semana: <strong>{{ $vigente['done'] }}</strong> de <strong>{{ $vigente['minutes'] }}</strong> minutos de aeróbico.</p>
                <x-barra-progresso class="mt-1.5 max-w-md" :progresso="$vigente['minutes'] > 0 ? intdiv($vigente['done'] * 100, $vigente['minutes']) : 0" cor="bg-saude-800 dark:bg-saude-200" />
                @if ($vigente['sessions'] !== [])
                    <ul class="mt-2 space-y-1 text-sm text-slate-600 dark:text-slate-300">
                        @foreach ($vigente['sessions'] as $s)
                            <li wire:key="sessao-{{ $s['weekday'] }}">{{ $nomesDosDias[$s['weekday']] }}: {{ mb_strtolower($s['type']->label()) }}, {{ $s['minutes'] }} min{{ $s['finisher'] ? ' no fim do treino' : '' }}</li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-1 text-xs text-slate-400">Confirme cada sessão em <a href="{{ route('health.activities.index') }}" wire:navigate class="underline">Minha semana</a>. Conta todo aeróbico feito, inclusive o esporte e a corrida.</p>
            </div>

            @include('livewire.activities.partials.progression', ['semanas' => $vigente['progression'], 'atual' => $p->is_light ? 1 : $vigente['step'], 'leveDoPlano' => $p->is_light])

            @if ($vigente['history'] !== [])
                <details class="text-sm">
                    <summary class="cursor-pointer font-medium text-slate-700 dark:text-slate-200">Semanas encerradas</summary>
                    <ul class="mt-1 divide-y divide-slate-100 text-slate-600 dark:divide-white/10 dark:text-slate-300">
                        @foreach ($vigente['history'] as $s)
                            <li class="py-1.5" wire:key="encerrada-{{ $s['week_start']->toDateString() }}">{{ $s['week_start']->format('d/m') }}: {{ $s['done'] }} de {{ $s['planned'] }} min, {{ $s['counted'] ? 'contou para avançar' : 'a seguinte repete' }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>
    @endif

    @if ($proposta)
        <form wire:submit="apply" class="space-y-4">
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $vigente ? 'Refazer o plano' : 'Atualizar meu programa' }}</h2>
                <p class="text-xs text-slate-400">Os exercícios que você já faz e o histórico deles ficam. Nada muda antes de você aplicar.</p>

                <div>
                    <p class="{{ $campo }}">Objetivo do plano</p>
                    <div class="mt-1.5 flex flex-wrap gap-2">
                        @foreach ($objetivos as $v => $rotulo)
                            <button type="button" wire:click="$set('goal', '{{ $v }}')" aria-pressed="{{ $goal === $v ? 'true' : 'false' }}" class="{{ $pilula($goal === $v) }}">{{ $rotulo }}</button>
                        @endforeach
                    </div>
                    @if ($goal === 'weight_loss')
                        <p class="mt-1 text-xs text-slate-400">O Cerne não promete perda de peso nem prazo: o resultado depende também da alimentação, que ele ainda não acompanha.</p>
                    @endif
                </div>

                <fieldset>
                    <legend class="{{ $campo }}">Dias em que você pode se exercitar</legend>
                    <div class="mt-1.5 flex flex-wrap gap-3">
                        @foreach ($nomesDosDias as $d => $nome)
                            <label class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300">
                                <input type="checkbox" wire:model.live="days" value="{{ $d }}" class="h-4 w-4 rounded border-slate-300"> {{ $nome }}
                                @if (isset($proposta['sport_days'][$d])) <span class="text-xs text-slate-400">(esporte)</span> @endif
                                @if (isset($proposta['running_days'][$d])) <span class="text-xs text-slate-400">(corrida)</span> @endif
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-slate-400">O plano deixa ao menos um dia livre. Dia de esporte já conta como aeróbico.</p>
                </fieldset>

                <fieldset>
                    <legend class="{{ $campo }}">Aeróbicos que você topa fazer</legend>
                    <div class="mt-1.5 flex flex-wrap gap-3">
                        @foreach ($tipos as $tipo)
                            <label class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="types" value="{{ $tipo->value }}" class="h-4 w-4 rounded border-slate-300"> {{ $tipo->label() }}</label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-slate-400">A corrida tem programa próprio, na aba Corrida. Com limitação física, escolha só o que o profissional liberou.</p>
                </fieldset>
            </section>

            <section class="card space-y-3 p-5" data-proposta>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">A semana proposta</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    Hoje você faz em média <strong>{{ $proposta['baseline']['total'] }} min</strong> de aeróbico por semana ({{ $proposta['baseline']['sport'] }} de esporte e {{ $proposta['baseline']['other'] }} de outros),
                    {{ $proposta['baseline']['source'] === 'diary' ? 'pelo diário '.($proposta['baseline']['weeks'] === 1 ? 'da última semana' : 'das últimas '.$proposta['baseline']['weeks'].' semanas') : 'pelo que está no seu perfil, porque o diário ainda não tem registros' }}.
                    {{ $proposta['light'] ? 'O plano leve fica em '.$proposta['target'].' min.' : 'O plano mira '.$proposta['target'].' min por semana.' }}
                </p>

                <ul class="divide-y divide-slate-100 text-sm dark:divide-white/10">
                    @foreach ($nomesDosDias as $d => $nome)
                        @php
                            $partes = [];
                            if (in_array($d, $proposta['layout']['strength'], true)) { $partes[] = 'Musculação'; }
                            if (isset($proposta['sport_days'][$d])) { $partes[] = 'Esporte ('.$proposta['sport_days'][$d].' min)'; }
                            if (isset($proposta['running_days'][$d])) { $partes[] = 'Programa de corrida ('.$proposta['running_days'][$d].' min)'; }
                            foreach ($proposta['week'] as $s) {
                                if ($s['weekday'] === $d) { $partes[] = $s['type']->label().', '.$s['minutes'].' min'.($s['finisher'] ? ' no fim do treino' : ''); }
                            }
                        @endphp
                        <li class="flex gap-3 py-1.5" wire:key="dia-{{ $d }}">
                            <span class="w-20 shrink-0 font-medium text-slate-700 dark:text-slate-200">{{ $nome }}</span>
                            <span @class(['min-w-0 text-slate-600 dark:text-slate-300', 'text-slate-400 dark:text-slate-500' => $partes === []])>{{ $partes !== [] ? implode(' + ', $partes) : 'Descanso' }}</span>
                        </li>
                    @endforeach
                </ul>

                @if (($semDiaCerto = $proposta['baseline']['sport'] - array_sum($proposta['sport_days'])) > 0)
                    <p class="text-xs text-slate-500 dark:text-slate-400" data-sem-dia-certo>Contam ainda {{ $semDiaCerto }} min por semana de esporte sem dia certo, que não aparecem na lista.</p>
                @endif

                @if ($proposta['layout']['strength'] === [])
                    <p class="text-xs text-slate-400">Sem musculação neste plano: marque dias livres ou crie uma fase na <a href="{{ route('health.gym.index') }}" wire:navigate class="underline">Academia</a>.</p>
                @endif
                @if ($proposta['layout']['leg_eve'] > 0)
                    <p class="text-xs text-amber-800 dark:text-amber-300">Com esses dias, sobra treino de pernas na véspera do esporte. Marque outros dias, se puder.</p>
                @endif
                @if ($proposta['missing_minutes'] > 0)
                    <p class="text-xs text-amber-800 dark:text-amber-300">Faltam {{ $proposta['missing_minutes'] }} min que não couberam nos dias marcados. Marque mais um dia, se puder.</p>
                @endif

                @include('livewire.activities.partials.progression', ['semanas' => $proposta['progression'], 'atual' => null, 'leveDoPlano' => $proposta['light']])
            </section>

            @if ($mudancas !== [] || $seriesMantidas || $sugestoesDePausa !== [])
                <section class="card space-y-3 p-5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">O que muda no treino da academia</h2>

                    @if ($seriesMantidas)
                        <p class="text-sm text-slate-600 dark:text-slate-300">As séries e repetições já ajustadas para perder peso continuam como estão.</p>
                    @endif

                    @if ($mudancas !== [])
                        <p class="text-sm text-slate-600 dark:text-slate-300">Os exercícios continuam os mesmos. Para perder peso, mudam séries, repetições e descanso. Desmarque o treino que preferir deixar como está.</p>
                        <x-rule-status key="weight_loss_strength" />
                        @foreach ($mudancas as $id => $treino)
                            <div class="rounded-lg border border-slate-200 p-3 dark:border-white/10" wire:key="mudanca-{{ $id }}">
                                <label class="flex items-center gap-2 text-sm font-medium text-slate-800 dark:text-slate-100"><input type="checkbox" wire:model.live="adjust" value="{{ $id }}" class="h-4 w-4 rounded border-slate-300"> {{ $treino['name'] }}</label>
                                <ul class="mt-1.5 space-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    @foreach ($treino['items'] as $item)
                                        <li><span class="font-medium text-slate-700 dark:text-slate-300">{{ $item['name'] }}:</span> {{ $item['from'] }} → {{ $item['to'] }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    @endif

                    @if ($sugestoesDePausa !== [])
                        <div class="space-y-2" data-pausas>
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-100">Pausar por causa das suas limitações</p>
                            @foreach ($sugestoesDePausa as $chave => $s)
                                <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300" wire:key="pausa-{{ $chave }}">
                                    <input type="checkbox" wire:model.live="pauses" value="{{ $chave }}" class="mt-0.5 h-4 w-4 rounded border-slate-300">
                                    <span class="min-w-0">{{ $s['name'] }} <span class="block text-xs text-slate-400">{{ implode(' ', $s['reasons']) }}</span></span>
                                </label>
                            @endforeach
                            <p class="text-xs text-slate-400">Para trocar um exercício ou incluir os indicados por um profissional, use <a href="{{ route('health.activities.review') }}" wire:navigate class="underline">Revisar treino</a>.</p>
                        </div>
                    @endif
                </section>
            @endif

            <x-form-consequence :lines="$consequencia" />
            <x-form-errors />
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Atualizar meu programa</button>
                @if ($vigente)
                    <button type="button" wire:click="$set('editing', false)" class="btn-secondary px-4 py-2 text-sm">Cancelar</button>
                @endif
            </div>
        </form>
    @endif
</div>
