<div class="space-y-6">

    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Agenda de Saúde</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Consultas e exames — visível para você e seu cônjuge. Avisamos por e-mail/push um dia antes.
            </p>
        </div>
        <button type="button" wire:click="newAppointment" class="btn-primary shrink-0 px-3 py-2 text-sm">+ Agendar</button>
    </div>

    <section class="card space-y-3 p-5">
        <p class="eyebrow">Próximas</p>
        @if ($upcoming->isEmpty())
            <p class="text-xs text-slate-400">Nada agendado.</p>
        @else
            <ul class="space-y-2">
                @foreach ($upcoming as $consulta)
                    <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="up-{{ $consulta->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                {{ $consulta->title }}
                                <span class="ml-1 rounded-full bg-saude-200/40 px-2 py-0.5 text-[10px] font-medium text-saude-800 dark:bg-saude-800/30 dark:text-saude-200">{{ $consulta->kind->label() }}</span>
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $consulta->member->name }} · {{ $consulta->scheduled_at->format('d/m/Y \à\s H:i') }}
                                @if ($consulta->location)
                                    · {{ $consulta->location }}
                                @endif
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" wire:click="editAppointment('{{ $consulta->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                            <button type="button" wire:click="delete('{{ $consulta->id }}')" wire:confirm="Remover este agendamento?" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">remover</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card space-y-3 p-5">
        <p class="eyebrow">Histórico</p>
        @if ($past->isEmpty())
            <p class="text-xs text-slate-400">Nenhum registro ainda.</p>
        @else
            <ul class="space-y-2">
                @foreach ($past as $consulta)
                    <li class="rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="past-{{ $consulta->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                    {{ $consulta->title }}
                                    <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-white/10 dark:text-slate-400">{{ $consulta->kind->label() }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    {{ $consulta->member->name }} · {{ $consulta->scheduled_at->format('d/m/Y') }}
                                    @if ($consulta->location)
                                        · {{ $consulta->location }}
                                    @endif
                                </p>
                                @if ($consulta->notes)
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $consulta->notes }}</p>
                                @endif
                            </div>
                            <button type="button" wire:click="editAppointment('{{ $consulta->id }}')" class="btn-ghost shrink-0 px-2 py-1 text-xs">Editar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExisting ? 'Editar' : 'Agendar' }}</h2>
                <button type="button" wire:click="closeForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa</label>
                    <select wire:model="memberId" class="select mt-1.5">
                        @foreach ($membros as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                    @error('memberId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                    <select wire:model="kind" class="select mt-1.5">
                        <option value="consultation">Consulta</option>
                        <option value="exam">Exame</option>
                    </select>
                    @error('kind') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Título</label>
                    <input type="text" wire:model="title" class="input mt-1.5" placeholder="Ex.: Cardiologista — Dr. Souza" maxlength="120">
                    @error('title') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
                    <input type="date" wire:model="scheduledDate" class="input mt-1.5">
                    @error('scheduledDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Hora</label>
                    <input type="time" wire:model="scheduledTime" class="input mt-1.5">
                    @error('scheduledTime') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Local (opcional)</label>
                    <input type="text" wire:model="location" class="input mt-1.5" placeholder="Ex.: Hospital Sírio-Libanês">
                    @error('location') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Anotações (opcional)</label>
                    <textarea wire:model="notes" class="input mt-1.5" rows="2" placeholder="O que aconteceu, resultado, próximos passos..."></textarea>
                    @error('notes') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit" class="btn-primary w-full">Salvar</button>
        </form>
    </x-modal>
</div>
