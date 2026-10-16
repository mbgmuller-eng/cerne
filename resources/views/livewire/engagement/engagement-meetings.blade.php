<div class="space-y-4">
    <div class="card p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="eyebrow">Próxima reunião</p>
            @if ($ehConsultor)
                <button type="button" wire:click="openForm" class="btn-secondary px-3 py-1.5 text-xs">{{ $proxima ? 'Marcar outra' : 'Marcar reunião' }}</button>
            @endif
        </div>

        @if ($proxima)
            <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="font-display text-xl font-medium text-slate-900 dark:text-white">{{ $proxima->scheduled_at->translatedFormat('l, d/m \à\s H:i') }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ $proxima->format->label() }} · {{ $proxima->duration_minutes }} min
                        @if ($proxima->location) · {{ $proxima->location }} @endif
                    </p>

                    @if ($proxima->agendaItems())
                        <p class="mt-3 text-xs font-medium text-slate-600 dark:text-slate-300">Pauta</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-slate-700 dark:text-slate-300">
                            @foreach ($proxima->agendaItems() as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="mt-3 text-xs text-slate-400">Sem pauta ainda. Os dois podem sugerir os assuntos.</p>
                    @endif

                    @if ($proxima->prep_notes)
                        <p class="mt-3 text-xs font-medium text-slate-600 dark:text-slate-300">O que levar</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $proxima->prep_notes }}</p>
                    @endif
                </div>

                <div class="flex shrink-0 flex-col items-stretch gap-2">
                    <button type="button" wire:click="openAgenda('{{ $proxima->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar pauta</button>
                    @if ($ehConsultor)
                        <button type="button" wire:click="openForm('{{ $proxima->id }}')" class="btn-ghost px-3 py-1.5 text-xs">Remarcar</button>
                        <button type="button" wire:click="openSummary('{{ $proxima->id }}')" class="btn-ghost px-3 py-1.5 text-xs">Registrar ata</button>
                        <button type="button" wire:click="cancelMeeting('{{ $proxima->id }}')" wire:confirm="Cancelar esta reunião? O cliente é avisado." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Cancelar</button>
                    @endif
                </div>
            </div>

            @if ($outrasAgendadas->isNotEmpty())
                <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                    Também marcadas:
                    {{ $outrasAgendadas->map(fn ($m) => $m->scheduled_at->format('d/m H:i'))->join(', ', ' e ') }}
                </p>
            @endif
        @else
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Nenhuma reunião marcada.@if (! $ehConsultor) O consultor marca a data e você acompanha por aqui.@endif</p>
        @endif
    </div>

    @if ($aguardandoAta->isNotEmpty())
        <div class="card p-5">
            <p class="eyebrow">Aguardando ata</p>
            <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($aguardandoAta as $m)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span class="text-slate-700 dark:text-slate-300">{{ $m->scheduled_at->format('d/m/Y \à\s H:i') }}</span>
                        @if ($ehConsultor)
                            <span class="flex gap-2">
                                <button type="button" wire:click="openSummary('{{ $m->id }}')" class="btn-secondary px-3 py-1 text-xs">Registrar ata</button>
                                <button type="button" wire:click="cancelMeeting('{{ $m->id }}')" wire:confirm="Esta reunião não aconteceu? Ela será marcada como cancelada." class="btn-ghost px-3 py-1 text-xs">Não aconteceu</button>
                            </span>
                        @else
                            <span class="text-xs text-slate-400">o consultor ainda não registrou a ata</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($historico->isNotEmpty())
        <div class="card p-5">
            <p class="eyebrow">Histórico de reuniões</p>
            <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($historico as $m)
                    <li class="py-3" wire:key="hist-{{ $m->id }}" x-data="{ aberta: false }">
                        <button type="button" @click="aberta = !aberta" class="flex w-full items-center justify-between gap-3 text-left">
                            <span class="text-sm text-slate-800 dark:text-slate-200">{{ $m->scheduled_at->format('d/m/Y') }} · {{ $m->format->label() }}</span>
                            <span @class([
                                'badge shrink-0',
                                'bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300' => $m->status->value === 'cancelled',
                                'bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300' => $m->status->value === 'done',
                            ])>{{ $m->status->label() }}</span>
                        </button>
                        <div x-show="aberta" x-cloak class="mt-2 space-y-2 text-sm text-slate-700 dark:text-slate-300">
                            @if ($m->agendaItems())
                                <p class="text-xs text-slate-500 dark:text-slate-400">Pauta: {{ implode(' · ', $m->agendaItems()) }}</p>
                            @endif
                            @if ($m->summary)
                                <p class="whitespace-pre-line">{{ $m->summary }}</p>
                            @elseif ($m->status->value === 'done')
                                <p class="text-xs text-slate-400">Sem ata registrada.</p>
                            @endif
                            @if ($ehConsultor && $m->status->value === 'done')
                                <button type="button" wire:click="openSummary('{{ $m->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar ata</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Marcar / remarcar --}}
    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingId ? 'Remarcar reunião' : 'Marcar reunião' }}</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
                    <input type="date" wire:model="date" class="input mt-1.5">
                    @error('date') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Horário</label>
                    <input type="time" wire:model="time" class="input mt-1.5">
                    @error('time') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Formato</label>
                    <select wire:model="format" class="select mt-1.5 w-full">
                        @foreach ($formatos as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Duração (minutos)</label>
                    <input type="number" wire:model="duration" min="10" max="480" step="5" class="input mt-1.5">
                    @error('duration') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Local ou link <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="text" wire:model="location" maxlength="255" class="input mt-1.5" placeholder="Link da videochamada ou endereço">
                    @error('location') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pauta <span class="font-normal text-slate-400">(um assunto por linha)</span></label>
                    <textarea wire:model="agenda" rows="3" maxlength="2000" class="input mt-1.5"></textarea>
                    @error('agenda') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que o cliente deve levar <span class="font-normal text-slate-400">(opcional)</span></label>
                    <textarea wire:model="prep" rows="2" maxlength="1000" class="input mt-1.5"></textarea>
                    @error('prep') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>

    {{-- Pauta e o que levar --}}
    <x-modal wire-model="showAgenda">
        <form wire:submit="saveAgenda" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Pauta da reunião</h2>
                <button type="button" wire:click="$set('showAgenda', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Assuntos <span class="font-normal text-slate-400">(um por linha)</span></label>
                <textarea wire:model="agendaText" rows="4" maxlength="2000" class="input mt-1.5"></textarea>
                @error('agendaText') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que levar <span class="font-normal text-slate-400">(opcional)</span></label>
                <textarea wire:model="prepText" rows="2" maxlength="1000" class="input mt-1.5"></textarea>
                @error('prepText') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>

    {{-- Ata --}}
    <x-modal wire-model="showSummary">
        <form wire:submit="saveSummary" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Ata da reunião</h2>
                <button type="button" wire:click="$set('showSummary', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">O resumo e as decisões ficam visíveis para o cliente. Anotações só suas vão em Notas privadas.</p>
            <div>
                <textarea wire:model="summaryText" rows="7" maxlength="5000" class="input"></textarea>
                @error('summaryText') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar e marcar como realizada</button>
            </div>
        </form>
    </x-modal>
</div>
