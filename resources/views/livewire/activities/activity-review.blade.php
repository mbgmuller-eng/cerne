@php
    use App\Enums\ActivityType;
    use App\Enums\LimitationStatus;
    $chave = fn (string $c) => str_replace(':', '_', $c);
    $rotulos = ['pause' => 'Pausar', 'swap' => 'Trocar por outro', 'keep' => 'Manter como está'];
    $respostas = ['ok' => 'pode', 'care' => 'pode, com cuidado', 'stop' => 'pausar'];
@endphp

<div class="space-y-5">
    <x-activity-header title="Revisar o treino" subtitle="O que as suas limitações pedem do treino atual. Você decide item por item.">
        <a href="{{ route('health.activities.consultation') }}" wire:navigate class="btn-secondary">Folha para o profissional</a>
    </x-activity-header>

    @if (session('status'))
        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    @if ($sugestoes['limitations']->isNotEmpty())
        <div class="card space-y-2 p-5" data-limitacoes-consideradas>
            <p class="eyebrow">Limitações consideradas</p>
            @foreach ($sugestoes['limitations'] as $l)
                <p class="text-sm text-slate-700 dark:text-slate-300">
                    <span class="font-semibold">{{ $l->regionLabel() }}</span> · {{ mb_strtolower($l->status->label()) }}
                    @if ($l->avoidLoads() !== []) · evitar {{ implode(', ', array_map(fn ($c) => mb_strtolower($c->label()), $l->avoidLoads())) }} @endif
                    · <a href="{{ $l->followup_id ? route('health.followups.show', $l->followup_id) : route('health.card.index') }}" wire:navigate class="text-saude-800 underline dark:text-saude-200">abrir</a>
                </p>
            @endforeach
            @if ($sugestoes['limitations']->contains(fn ($l) => $l->status === LimitationStatus::Investigating))
                <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-white/5 dark:text-slate-300">Em investigação, o Cerne só sugere <strong>pausar</strong> por precaução. Trocar passa a ser oferecido depois da orientação, e nada é acrescentado sem indicação.</p>
            @endif
            @foreach ($esportes as $e)
                @if ($e['answer'] !== 'ok')
                    <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-300">Sobre {{ mb_strtolower(ActivityType::tryFrom($e['type'])?->label() ?? $e['type']) }}, o profissional respondeu: {{ $respostas[$e['answer']] }} ({{ mb_strtolower($e['region']) }}).</p>
                @endif
            @endforeach
        </div>
    @endif

    <p class="text-xs text-slate-400">As marcações vêm do nome de cada exercício: são uma sugestão por precaução, não uma avaliação. Confira com o profissional.</p>

    @if (! $plano)
        <div class="card p-5"><p class="text-sm text-slate-600 dark:text-slate-300">Não há fase de treino ativa. <a href="{{ route('health.gym.index') }}" wire:navigate class="text-saude-800 underline dark:text-saude-200">Abrir a Academia</a></p></div>
    @elseif ($total === 0)
        <div class="card p-5" data-sem-sugestoes><p class="text-sm text-slate-600 dark:text-slate-300">{{ $sugestoes['limitations']->isEmpty() ? 'Nenhuma limitação ativa está sendo considerada, e não há nada a devolver ao treino.' : 'Nenhuma sugestão pendente para este treino.' }}</p></div>
    @else
        <form wire:submit="apply" class="space-y-4">
            @if ($sugestoes['restores'] !== [])
                <section class="card space-y-2 p-5" data-devolucoes>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Pode voltar</h2>
                    <p class="text-xs text-slate-400">A limitação que motivou estas mudanças não está mais ativa.</p>
                    @foreach ($sugestoes['restores'] as $k => $r)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 py-2 dark:border-white/10" wire:key="volta-{{ $r['adjustment_id'] }}">
                            <p class="min-w-0 text-sm text-slate-700 dark:text-slate-300">{{ $r['text'] }}</p>
                            <label class="sr-only" for="d-{{ $chave($k) }}">Decisão</label>
                            <select id="d-{{ $chave($k) }}" wire:model.live="decisions.{{ $chave($k) }}.action" class="select w-auto py-1.5 text-sm">
                                <option value="">Voltar (sugerido)</option>
                                <option value="keep">Deixar como está</option>
                            </select>
                        </div>
                    @endforeach
                </section>
            @endif

            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($treinos as $t)
                    <section class="card min-w-0 space-y-3 p-5" wire:key="rev-{{ $t['id'] }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $t['name'] }}</h2>
                                @if ($t['focus']) <p class="text-xs text-slate-400">{{ $t['focus'] }}</p> @endif
                            </div>
                            <span @class(['rounded-full px-2 py-0.5 text-xs font-medium tabular-nums', 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300' => $t['over'] > 0, 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-200' => $t['over'] === 0])>cerca de {{ $t['minutes'] }} min</span>
                        </div>

                        @foreach ($t['suggested'] as $k => $i)
                            @php $acao = $decisions[$chave($k)]['action'] ?? ''; @endphp
                            <div class="space-y-2 rounded-xl border border-amber-300 p-3 dark:border-amber-500/40" wire:key="sug-{{ $i['item_id'] }}" data-sugestao>
                                <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $i['name'] }} <span class="font-normal text-slate-500 dark:text-slate-400">· {{ $i['dose'] }}</span></p>
                                @foreach ($i['reasons'] as $motivo) <p class="text-xs text-slate-500 dark:text-slate-400">{{ $motivo }}</p> @endforeach
                                <label class="sr-only" for="d-{{ $chave($k) }}">Decisão para {{ $i['name'] }}</label>
                                <select id="d-{{ $chave($k) }}" wire:model.live="decisions.{{ $chave($k) }}.action" class="select w-full py-1.5 text-sm">
                                    <option value="">{{ $rotulos[$i['default']] }} (sugerido)</option>
                                    @foreach ($i['actions'] as $a)
                                        @if ($a !== $i['default']) <option value="{{ $a }}">{{ $rotulos[$a] }}</option> @endif
                                    @endforeach
                                </select>
                                @if ($acao === 'swap')
                                    <div wire:key="troca-{{ $i['item_id'] }}">
                                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400" for="s-{{ $chave($k) }}">Entra no lugar</label>
                                        <select id="s-{{ $chave($k) }}" wire:model.live="decisions.{{ $chave($k) }}.swap" class="select mt-1 w-full py-1.5 text-sm">
                                            <option value="">Escolha</option>
                                            @foreach ($trocas[$k] ?? [] as $op)
                                                <option value="{{ $op['key'] }}">{{ $op['name'] }}{{ $op['equipment'] ? ' · '.$op['equipment'] : '' }}</option>
                                            @endforeach
                                        </select>
                                        <p class="mt-1 text-xs text-slate-400">Do mesmo grupo muscular, sem o que as suas limitações marcam.</p>
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        @if ($t['incoming']->isNotEmpty())
                            <div class="rounded-xl border border-slate-200 p-3 dark:border-white/10">
                                <p class="text-xs font-semibold text-slate-700 dark:text-slate-200">Indicados que entram no começo</p>
                                @foreach ($t['incoming'] as $a) <p class="text-sm text-slate-700 dark:text-slate-300">{{ $a['name'] }}@if ($a['dose']) · {{ $a['dose'] }}@endif</p> @endforeach
                            </div>
                        @endif

                        @if ($t['over'] > 0)
                            <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-300">Passa {{ $t['over'] }} min dos seus {{ $limite }}.</p>
                        @endif

                        <details>
                            <summary class="cursor-pointer text-xs font-medium text-saude-800 dark:text-saude-200">{{ $t['untouched']->count() }} sem mudança sugerida</summary>
                            <ul class="mt-1 space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
                                @foreach ($t['untouched'] as $i) <li>{{ $i->exercise->name }}</li> @endforeach
                            </ul>
                        </details>
                    </section>
                @endforeach
            </div>

            @if ($sugestoes['additions'] !== [])
                <section class="card space-y-3 p-5" data-indicados>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Exercícios indicados pelo profissional</h2>
                    <p class="text-xs text-slate-400">Vêm do programa do acompanhamento. Marque em quais treinos cada um entra.</p>
                    @foreach ($sugestoes['additions'] as $k => $a)
                        <fieldset class="border-t border-slate-100 pt-2 dark:border-white/10" wire:key="add-{{ $a['source_id'] }}">
                            <legend class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $a['name'] }}@if ($a['dose']) <span class="font-normal text-slate-500">· {{ $a['dose'] }}</span>@endif · {{ $a['per_week'] }}x por semana</legend>
                            <div class="mt-1 flex flex-wrap gap-3">
                                @foreach ($treinos as $t)
                                    <label class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="targets.{{ $chave($k) }}" value="{{ $t['id'] }}" class="h-4 w-4 rounded border-slate-300"> {{ $t['name'] }}</label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                    @if ($passaDoTempo)
                        <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="fit" class="mt-0.5 h-4 w-4 rounded border-slate-300"> Tirar uma série dos exercícios fora do foco do esporte até caber nos {{ $limite }} min</label>
                    @endif
                </section>
            @endif

            <div class="card space-y-3 p-5">
                <x-form-consequence :lines="$consequencia" />
                @error('form') <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-300" data-form-error>{{ $message }}</p> @enderror
                <button type="submit" class="btn-primary px-4 py-2 text-sm" wire:loading.attr="disabled" wire:target="apply">Aplicar no treino</button>
            </div>
        </form>
    @endif

    @if ($ajustes->isNotEmpty())
        <details class="card p-5">
            <summary class="cursor-pointer text-sm font-semibold text-slate-900 dark:text-white">O que a revisão já mudou nesta fase ({{ $ajustes->count() }})</summary>
            <ul class="mt-2 divide-y divide-slate-100 text-sm text-slate-600 dark:divide-white/10 dark:text-slate-300">
                @foreach ($ajustes as $a)
                    <li class="py-1.5" wire:key="aj-{{ $a->id }}">
                        {{ $a->created_at->format('d/m/Y') }} · {{ $a->workout?->name }}:
                        {{ match ($a->kind) { 'paused' => 'pausado '.$a->exercise?->name, 'swapped' => $a->exercise?->name.' trocado por '.$a->replacement?->name, 'added' => 'entrou '.$a->exercise?->name, 'reps' => 'séries e repetições de '.$a->exercise?->name.' ajustadas', default =>'uma série a menos em '.$a->exercise?->name } }}{{ $a->reverted_at ? ' (já resolvido)' : '' }}
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
