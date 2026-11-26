@php $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400'; @endphp

<div class="space-y-5">
    <x-activity-header title="Folha para o profissional" subtitle="Leve as suas atividades à consulta e registre aqui o que ouvir." />

    <div class="card space-y-3 p-5">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Antes da consulta</h2>
        <p class="text-sm text-slate-600 dark:text-slate-300">A folha em PDF traz a sua semana habitual e os exercícios do treino atual, com espaço para o profissional marcar <strong>pode</strong>, <strong>adaptar</strong> ou <strong>evitar</strong> e escrever o que você deve fazer.</p>
        <a href="{{ route('health.activities.sheet') }}" class="btn-secondary inline-flex px-3 py-2 text-sm" data-baixar-folha>Baixar a folha (PDF)</a>
    </div>

    @if ($limitacoes->isEmpty())
        <div class="card space-y-2 p-5" data-sem-limitacao>
            <p class="text-sm text-slate-600 dark:text-slate-300">Para registrar as respostas, salve antes a limitação na Ficha de Saúde ou em um acompanhamento.</p>
            <a href="{{ route('health.card.index') }}" wire:navigate class="btn-primary inline-flex px-3 py-1.5 text-sm">Abrir a ficha</a>
        </div>
    @else
        <form wire:submit="save" class="space-y-4">
            <div class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Depois da consulta</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $campo }}">A consulta foi sobre</label>
                        <select wire:model.live="limitationId" class="select mt-1.5 w-full">
                            @foreach ($limitacoes as $l)
                                <option value="{{ $l->id }}">{{ $l->regionLabel() }} · {{ $l->followup?->title ?? 'na ficha' }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($campoProfissional)
                        <div wire:key="profissional-{{ $limitationId }}">
                            <x-health.professional-field model="professional" label="Quem orientou" :field="$campoProfissional" maxlength="160" />
                        </div>
                    @endif
                </div>
                <div>
                    <label class="{{ $campo }}">O que o profissional orientou</label>
                    <textarea wire:model="guidance" rows="3" class="input mt-1.5" maxlength="2000" placeholder="Escreva como ele falou"></textarea>
                </div>
            </div>

            <div class="card space-y-2 p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Exercícios do seu treino</h2>
                @if (! $temPlano)
                    <p class="text-sm text-slate-500 dark:text-slate-400">Não há fase de treino ativa para marcar.</p>
                @else
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="allExercises" class="h-4 w-4 rounded border-slate-300"> Mostrar todos (sem isso, só os que exigem do que está marcado)</label>
                    @forelse ($marcaveis as $id => $nomeDoExercicio)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 py-2 dark:border-white/10" wire:key="marca-{{ $id }}">
                            <label for="marca-{{ $id }}" class="min-w-0 text-sm text-slate-700 dark:text-slate-300">{{ $nomeDoExercicio }}</label>
                            <select id="marca-{{ $id }}" wire:model.live="marks.{{ $id }}" class="select w-auto py-1.5 text-sm">
                                <option value="">Não foi falado</option>
                                <option value="ok">Pode</option>
                                <option value="adapt">Adaptar</option>
                                <option value="avoid">Evitar</option>
                            </select>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum exercício marcado por esta limitação. Use "Mostrar todos" se o profissional falou de algum.</p>
                    @endforelse
                @endif
            </div>

            <div class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Corrida e esportes</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="{{ $campo }}">Corrida</label>
                        <select wire:model="running" class="select mt-1.5 w-full">
                            <option value="">Não foi falado</option>
                            <option value="yes">Pode</option>
                            <option value="not_yet">Ainda não</option>
                        </select>
                    </div>
                    @foreach ($esportes as $tipo => $rotulo)
                        <div wire:key="esporte-{{ $tipo }}">
                            <label class="{{ $campo }}">{{ $rotulo }}</label>
                            <select wire:model="sports.{{ $tipo }}" class="select mt-1.5 w-full">
                                <option value="">Não foi falado</option>
                                <option value="ok">Pode</option>
                                <option value="care">Pode, com cuidado</option>
                                <option value="stop">Pausar</option>
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Exercícios que o profissional indicou</h2>
                <p class="text-xs text-slate-400">Escreva como ele passou. Vão para o programa do acompanhamento e, com a sua aprovação, para o treino.</p>
                @foreach ($exercises as $i => $e)
                    <div class="flex items-center justify-between gap-3 border-t border-slate-100 py-1.5 dark:border-white/10" wire:key="indicado-{{ $i }}-{{ md5($e['name']) }}">
                        <p class="min-w-0 break-words text-sm text-slate-800 dark:text-slate-200">{{ $e['name'] }}@if ($e['sets'] || $e['reps']) · {{ collect([$e['sets'], $e['reps']])->filter()->implode(' × ') }}@endif · {{ $e['per_week'] }}x por semana</p>
                        <button type="button" wire:click="removeExercise({{ $i }})" class="shrink-0 text-xs text-slate-400 hover:text-red-700">tirar</button>
                    </div>
                @endforeach
                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="sm:col-span-4"><label class="{{ $campo }}">Exercício</label><input type="text" wire:model="exName" wire:keydown.enter.prevent="addExercise" class="input mt-1.5" maxlength="120" placeholder="Como o profissional chamou"></div>
                    <div><label class="{{ $campo }}">Séries</label><input type="text" inputmode="numeric" wire:model="exSets" class="input mt-1.5" placeholder="3"></div>
                    <div class="sm:col-span-2"><label class="{{ $campo }}">Repetições ou tempo</label><input type="text" wire:model="exReps" class="input mt-1.5" placeholder="12 ou 30 s"></div>
                    <div><label class="{{ $campo }}">Vezes por semana</label><input type="text" inputmode="numeric" wire:model="exPerWeek" class="input mt-1.5"></div>
                </div>
                <button type="button" wire:click="addExercise" class="btn-secondary px-3 py-1.5 text-sm">Adicionar exercício</button>
            </div>

            <div class="card space-y-3 p-5">
                <x-form-consequence :lines="$consequencia" />
                <x-form-errors />
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Registrar respostas</button>
            </div>
        </form>
    @endif
</div>
