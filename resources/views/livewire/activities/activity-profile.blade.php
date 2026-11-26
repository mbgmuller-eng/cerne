@php $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400'; @endphp

<div class="space-y-5">
    <x-activity-header title="Meu perfil de atividades" subtitle="Objetivos, o que você já faz e quando prefere treinar." />

    @if (session('status'))
        <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('status') }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div class="space-y-4">
            <form wire:submit="save" class="card min-w-0 space-y-4 p-5">
                <div>
                    <p class="eyebrow">Meus objetivos, em ordem</p>
                    <p class="mt-1 text-xs text-slate-400">Quando a semana não comporta tudo, vale a ordem.</p>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($goals as $i => $objetivo)
                            <li class="flex items-center gap-2 py-2" wire:key="objetivo-{{ $i }}-{{ md5($objetivo) }}">
                                <span class="rounded-full bg-saude-200/40 px-2 py-0.5 text-xs font-semibold text-saude-800 dark:bg-saude-800/40 dark:text-saude-200">{{ $i + 1 }}º</span>
                                <span class="min-w-0 flex-1 break-words text-sm text-slate-800 dark:text-slate-200">{{ $objetivo }}</span>
                                <button type="button" wire:click="moveGoal({{ $i }})" @disabled($i === 0) class="btn-ghost px-2 py-1 text-xs disabled:opacity-30" aria-label="Subir {{ $objetivo }}">↑</button>
                                <button type="button" wire:click="removeGoal({{ $i }})" class="text-xs text-slate-400 hover:text-red-700" aria-label="Tirar {{ $objetivo }}">tirar</button>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-2 flex gap-2">
                        <label class="sr-only" for="novo-objetivo">Novo objetivo</label>
                        <input id="novo-objetivo" type="text" wire:model="newGoal" wire:keydown.enter.prevent="addGoal" class="input min-w-0 flex-1" maxlength="120" placeholder="Ex.: jogar sem dor">
                        <button type="button" wire:click="addGoal" class="btn-secondary px-3 py-2 text-sm">Adicionar</button>
                    </div>
                </div>

                <fieldset>
                    <legend class="eyebrow">Dias preferidos da academia</legend>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach ($dias as $d => $nomeDoDia)
                            <label class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300"><input type="checkbox" wire:model.live="gymDays" value="{{ $d }}" class="h-4 w-4 rounded border-slate-300"> {{ $nomeDoDia }}</label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-slate-400">São preferências. Na semana você muda o treino de dia quando quiser.</p>
                </fieldset>

                <div class="max-w-xs">
                    <label class="{{ $campo }}">Tempo por treino, em minutos</label>
                    <input type="text" inputmode="numeric" wire:model="sessionMinutes" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">Serve para avisar quando os exercícios novos não cabem.</p>
                </div>

                <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" wire:model.live="consider" class="mt-0.5 h-4 w-4 rounded border-slate-300">
                    Considerar nos programas as limitações da minha Saúde
                </label>

                <x-form-consequence :lines="$consequencia" />
                <x-form-errors />
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar perfil</button>
            </form>
        </div>

        <div class="space-y-4">
            <div class="card min-w-0 space-y-3 p-5">
                <div class="flex items-center justify-between gap-2">
                    <p class="eyebrow">O que eu já faço</p>
                    <button type="button" wire:click="openCommitment('')" class="btn-ghost px-2 py-1 text-xs">+ Atividade</button>
                </div>
                @forelse ($compromissos as $c)
                    <div class="flex items-start justify-between gap-3 border-t border-slate-100 py-2 first:border-t-0 dark:border-white/10" wire:key="comp-{{ $c->id }}">
                        <div class="min-w-0">
                            <p class="break-words text-sm font-medium text-slate-800 dark:text-slate-200">{{ $c->name() }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $c->isFixed() ? 'Fixo: '.mb_strtolower($dias[$c->weekday]) : 'Sem dia certo: '.$c->times_per_week.'x por semana' }}, {{ $c->minutes }} min, esforço {{ $c->effort }}</p>
                        </div>
                        <button type="button" wire:click="openCommitment('{{ $c->id }}')" class="shrink-0 text-xs text-slate-500 underline dark:text-slate-400">editar</button>
                    </div>
                @empty
                    <p class="text-sm text-slate-500 dark:text-slate-400">Conte o que você pratica: aulas e jogos com dia certo, ou o que faz quando dá.</p>
                @endforelse

                @if ($showCommitment)
                    <form wire:submit="saveCommitment" class="space-y-3 rounded-xl border border-dashed border-slate-200 p-3 dark:border-white/10">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div><label class="{{ $campo }}">Atividade</label><select wire:model.live="cType" class="select mt-1.5 w-full">@foreach ($tipos as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></div>
                            <div><label class="{{ $campo }}">Tem dia certo?</label><select wire:model.live="cKind" class="select mt-1.5 w-full"><option value="fixed">Sim, dia fixo</option><option value="flexible">Não, varia</option></select></div>
                            @if ($cType === 'other')
                                <div class="sm:col-span-2" wire:key="nome-do-compromisso"><label class="{{ $campo }}">Nome da atividade</label><input type="text" wire:model="cName" class="input mt-1.5" maxlength="60"></div>
                            @endif
                            @if ($cKind === 'fixed')
                                <div wire:key="dia-do-compromisso"><label class="{{ $campo }}">Dia da semana</label><select wire:model.live="cWeekday" class="select mt-1.5 w-full">@foreach ($dias as $d => $r)<option value="{{ $d }}">{{ $r }}</option>@endforeach</select></div>
                            @else
                                <div wire:key="vezes-do-compromisso"><label class="{{ $campo }}">Vezes por semana</label><input type="text" inputmode="numeric" wire:model.live.blur="cTimes" class="input mt-1.5"></div>
                            @endif
                            <div><label class="{{ $campo }}">Duração em minutos</label><input type="text" inputmode="numeric" wire:model="cMinutes" class="input mt-1.5"></div>
                        </div>
                        <x-scale-input model="cEffort" :value="$cEffort" label="Esforço habitual de 0 a 10" />
                        <x-form-consequence :lines="$consequenciaCompromisso" />
                        <x-form-errors />
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="submit" class="btn-primary px-3 py-1.5 text-sm">{{ $commitmentId ? 'Salvar alterações' : 'Adicionar' }}</button>
                            <button type="button" wire:click="$set('showCommitment', false)" class="text-xs text-slate-400">Cancelar</button>
                            @if ($commitmentId)
                                <button type="button" wire:click="removeCommitment('{{ $commitmentId }}')" wire:confirm="Tirar da lista? O que já está no diário continua." class="text-xs text-slate-400 hover:text-red-700">Tirar da lista</button>
                            @endif
                        </div>
                    </form>
                @endif
            </div>

            <div class="card min-w-0 space-y-3 p-5" data-saude-no-perfil>
                <p class="eyebrow">Encontrado na sua Saúde</p>
                @forelse ($limitacoes as $l)
                    <div class="rounded-xl border p-3 {{ $l->isActive() ? 'border-amber-300 dark:border-amber-500/40' : 'border-slate-200 dark:border-white/10' }}" wire:key="lim-{{ $l->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $l->regionLabel() }}</p>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-white/10 dark:text-slate-200">{{ $l->status->label() }}</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $l->followup ? 'Acompanhamento: '.$l->followup->title : 'Na Ficha de Saúde' }}</p>
                        @if ($l->avoidLoads() !== []) <p class="text-sm text-slate-700 dark:text-slate-300">Evitar: {{ implode(', ', array_map(fn ($c) => mb_strtolower($c->label()), $l->avoidLoads())) }}.</p> @endif
                        <a href="{{ $l->followup_id ? route('health.followups.show', $l->followup_id) : route('health.card.index') }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-saude-800 underline dark:text-saude-200">{{ $l->followup_id ? 'Abrir no acompanhamento' : 'Abrir na ficha' }}</a>
                    </div>
                @empty
                    <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma limitação salva. Ela é registrada na Ficha de Saúde ou dentro de um acompanhamento.</p>
                @endforelse
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('health.card.index') }}" wire:navigate class="btn-secondary px-3 py-1.5 text-sm">Registrar na ficha</a>
                    @if ($pendentes > 0)
                        <a href="{{ route('health.activities.review') }}" wire:navigate class="btn-primary px-3 py-1.5 text-sm">Revisar treino ({{ $pendentes }})</a>
                    @endif
                </div>
                <p class="text-xs text-slate-400">O perfil e os programas são só seus. A limitação segue a visibilidade da Agenda de Saúde.</p>
            </div>
        </div>
    </div>
</div>
