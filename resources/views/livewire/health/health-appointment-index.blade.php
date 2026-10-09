<div class="space-y-6">

    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Agenda de Saúde</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Consultas e exames, com quem atende, onde fica e como falar com o local. Visível para você e seu cônjuge. Avisamos um dia antes.
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
                            </p>
                            <x-appointment-details :consulta="$consulta" />
                            <x-calendar-actions :consulta="$consulta" :show-person="$membros->count() > 1" />
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
                                </p>
                                <x-appointment-details :consulta="$consulta" />
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

            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa</label>
                    <select wire:model="memberId" class="select mt-1.5 w-full">
                        @foreach ($membros as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                    @error('memberId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                    <select wire:model.live="kind" class="select mt-1.5 w-full">
                        <option value="consultation">Consulta</option>
                        <option value="exam">Exame</option>
                    </select>
                    @error('kind') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                @if ($kind === 'consultation')
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Profissional</label>
                        <input type="text" wire:model="professionalName" class="input mt-1.5" placeholder="Ex.: Dr. João Silva" maxlength="120">
                        @error('professionalName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Especialidade</label>
                        <input type="text" wire:model="specialty" class="input mt-1.5" placeholder="Ex.: Otorrinolaringologia" maxlength="80">
                        @error('specialty') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ $kind === 'consultation' ? 'Título' : 'Nome do exame' }}
                        @if ($kind === 'consultation')<span class="font-normal text-slate-400">(opcional se informar profissional ou especialidade)</span>@endif
                    </label>
                    <input type="text" wire:model="title" class="input mt-1.5" placeholder="{{ $kind === 'consultation' ? 'Ex.: Retorno do aparelho auditivo' : 'Ex.: Hemograma completo' }}" maxlength="120">
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

                <div class="@sm:col-span-2 border-t border-slate-100 pt-4 dark:border-white/10">
                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Onde fica</p>
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Estabelecimento <span class="font-normal text-slate-400">(clínica, hospital, laboratório)</span></label>
                    <input type="text" wire:model="location" class="input mt-1.5" placeholder="Ex.: Clínica Vida" maxlength="160">
                    @error('location') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Endereço</label>
                    <input type="text" wire:model="address" autocomplete="off" class="input mt-1.5" placeholder="Ex.: Rua das Flores, 100, sala 3" maxlength="200">
                    @error('address') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Telefone ou contato</label>
                    <input type="tel" wire:model="phone" autocomplete="off" class="input mt-1.5" placeholder="(41) 3000-0000" maxlength="30">
                    @error('phone') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2 border-t border-slate-100 pt-4 dark:border-white/10">
                    <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Agendamento</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Agendado por</label>
                    <input type="text" wire:model="bookedByName" class="input mt-1.5" placeholder="Quem da família marcou" maxlength="120">
                    @error('bookedByName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Agendado com <span class="font-normal text-slate-400">(secretária ou atendente)</span></label>
                    <input type="text" wire:model="bookedWithName" class="input mt-1.5" placeholder="Ex.: Maria, secretária" maxlength="120">
                    @error('bookedWithName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações</label>
                    <textarea wire:model="notes" class="input mt-1.5" rows="2" placeholder="Antes: levar exames anteriores. Depois: o que aconteceu, resultado, próximos passos."></textarea>
                    @error('notes') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit" class="btn-primary w-full">Salvar</button>
        </form>
    </x-modal>
</div>
