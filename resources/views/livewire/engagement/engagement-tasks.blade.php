<section class="card p-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Tarefas</h2>
        <button type="button" wire:click="openForm" class="btn-secondary px-3 py-1.5 text-xs">Nova tarefa</button>
    </div>

    <div class="mt-3 flex gap-1 rounded-lg bg-slate-100 p-1 text-xs dark:bg-white/5">
        @foreach (['todas' => 'Todas', 'cliente' => 'Do cliente', 'consultor' => 'Do consultor'] as $chave => $rotulo)
            <button type="button" wire:click="$set('filtro', '{{ $chave }}')" @class([
                'flex-1 rounded-md px-2 py-1 font-medium transition',
                'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' => $filtro === $chave,
                'text-slate-500 dark:text-slate-400' => $filtro !== $chave,
            ])>{{ $rotulo }}</button>
        @endforeach
    </div>

    @if ($abertas->isEmpty())
        <p class="mt-4 text-sm text-slate-500 dark:text-slate-400">Nenhuma tarefa em aberto.</p>
    @else
        <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($abertas as $t)
                @php
                    $podeMexer = $visitante->isConsultant() || $t->created_by_user_id === $visitante->userId;
                    $podeStatus = $podeMexer || $t->owner === \App\Enums\EngagementTaskOwner::Client;
                @endphp
                <li class="py-3" wire:key="tarefa-{{ $t->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm text-slate-800 dark:text-slate-200">{{ $t->title }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $t->owner->label() }}
                                @if ($t->due_date) · até {{ $t->due_date->format('d/m') }} @endif
                            </p>
                            @if ($t->description)
                                <p class="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{{ $t->description }}</p>
                            @endif
                        </div>
                        @if ($t->isOverdue())
                            <span class="badge shrink-0 bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300">atrasada</span>
                        @elseif ($t->due_date && $t->due_date->isSameDay(today()->addDay()))
                            <span class="badge shrink-0 bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-300">vence amanhã</span>
                        @endif
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        @if ($podeStatus)
                            <select wire:change="setStatus('{{ $t->id }}', $event.target.value)" class="select w-auto py-1 text-xs">
                                @foreach ($situacoes as $valor => $rotulo)
                                    <option value="{{ $valor }}" @selected($t->status->value === $valor)>{{ $rotulo }}</option>
                                @endforeach
                            </select>
                        @endif
                        @if ($podeMexer)
                            <button type="button" wire:click="openForm('{{ $t->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                            <button type="button" wire:click="delete('{{ $t->id }}')" wire:confirm="Apagar esta tarefa?" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Apagar</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($concluidas->isNotEmpty())
        <details class="mt-4 text-xs">
            <summary class="cursor-pointer text-slate-500 dark:text-slate-400">{{ $concluidas->count() }} concluída{{ $concluidas->count() === 1 ? '' : 's' }} recentemente</summary>
            <ul class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                @foreach ($concluidas as $t)
                    <li class="flex items-center justify-between gap-3" wire:key="feita-{{ $t->id }}">
                        <span class="min-w-0 truncate line-through decoration-slate-300">{{ $t->title }}</span>
                        <span class="flex shrink-0 items-center gap-2 text-slate-400">
                            {{ $t->completed_at?->format('d/m') }}
                            @if ($visitante->isConsultant() || $t->created_by_user_id === $visitante->userId || $t->owner === \App\Enums\EngagementTaskOwner::Client)
                                <button type="button" wire:click="setStatus('{{ $t->id }}', 'todo')" class="underline">reabrir</button>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingId ? 'Editar tarefa' : 'Nova tarefa' }}</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que precisa ser feito</label>
                    <input type="text" wire:model="title" maxlength="160" class="input mt-1.5" placeholder="Ex.: Enviar a apólice de vida atual">
                    @error('title') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Responsável</label>
                    <select wire:model="owner" class="select mt-1.5 w-full">
                        @foreach ($donos as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('owner') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Prazo <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="date" wire:model="dueDate" class="input mt-1.5">
                    @error('dueDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                @if ($reunioes->isNotEmpty())
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Ligada a uma reunião <span class="font-normal text-slate-400">(opcional)</span></label>
                        <select wire:model="meetingId" class="select mt-1.5 w-full">
                            <option value="">Nenhuma</option>
                            @foreach ($reunioes as $r)
                                <option value="{{ $r->id }}">{{ $r->scheduled_at->format('d/m/Y H:i') }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Detalhes <span class="font-normal text-slate-400">(opcional)</span></label>
                    <textarea wire:model="description" rows="3" maxlength="2000" class="input mt-1.5"></textarea>
                    @error('description') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>
</section>
