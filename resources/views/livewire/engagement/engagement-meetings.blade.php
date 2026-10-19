<div class="space-y-4">
    <div class="card p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="eyebrow">Próxima reunião</p>
            @if ($ehConsultor)
                <button type="button" wire:click="openForm" class="btn-secondary px-3 py-1.5 text-xs">{{ $proxima ? 'Marcar outra' : 'Marcar reunião' }}</button>
            @endif
        </div>

        @if ($proxima)
            <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <p class="font-display text-2xl font-medium text-slate-900 first-letter:uppercase dark:text-white">{{ $proxima->scheduled_at->translatedFormat('l, d/m') }}</p>
                <p class="font-display text-2xl font-medium text-accent-700 dark:text-accent-400">{{ $proxima->scheduled_at->format('H:i') }}</p>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $proxima->format->label() }}</span>
                <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $proxima->duration_minutes }} min</span>
                @if ($proxima->location && mb_strtolower(trim($proxima->location)) !== mb_strtolower($proxima->format->label()))
                    <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $proxima->location }}</span>
                @endif
            </div>

            <div class="mt-4 space-y-3 border-t border-slate-100 pt-4 dark:border-white/10">
                @if ($proxima->agendaItems())
                    <div>
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-300">Pauta</p>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-sm text-slate-700 dark:text-slate-300">
                            @foreach ($proxima->agendaItems() as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <p class="text-xs text-slate-400">Sem pauta ainda. Os dois podem sugerir os assuntos.</p>
                @endif

                @if ($proxima->prep_notes)
                    <div>
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-300">O que levar</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $proxima->prep_notes }}</p>
                    </div>
                @endif

                <div class="space-y-1.5">
                    @include('livewire.engagement.partials.attachment-chips', ['anexos' => $anexos->get($proxima->id, collect()), 'visitante' => $visitante])
                    <button type="button" wire:click="openAttach('meeting', '{{ $proxima->id }}')" class="btn-ghost -ml-2 px-2 py-1 text-xs">+ Anexar documento</button>
                </div>
            </div>

            {{-- Ações: as de todo dia em fila, no mesmo estilo; o que cancela fica à parte, no "Mais". --}}
            <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4 dark:border-white/10">
                <button type="button" wire:click="openAgenda('{{ $proxima->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar pauta</button>
                @if ($ehConsultor)
                    <button type="button" wire:click="openForm('{{ $proxima->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Remarcar</button>
                    <button type="button" wire:click="openSummary('{{ $proxima->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Registrar ata</button>
                @endif
                <a href="{{ route('engagement.meeting.ics', $proxima->id) }}" class="btn-ghost px-3 py-1.5 text-xs">Adicionar à agenda</a>

                @if ($ehConsultor)
                    <div class="relative ml-auto" x-data="{ aberto: false }" @keydown.escape.window="aberto = false">
                        <button type="button" @click="aberto = ! aberto" @click.outside="aberto = false" :aria-expanded="aberto" aria-haspopup="menu" class="btn-ghost px-3 py-1.5 text-xs">
                            Mais
                            <svg class="ml-1 inline h-3 w-3 transition" :class="aberto ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                        </button>
                        <div x-show="aberto" x-cloak role="menu" class="absolute right-0 z-20 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-slate-800">
                            <button type="button" role="menuitem" wire:click="cancelMeeting('{{ $proxima->id }}')" wire:confirm="Cancelar esta reunião? O cliente é avisado." class="flex w-full items-center rounded-lg px-3 py-2 text-left text-xs text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Cancelar esta reunião</button>
                            @if ($temSerie)
                                <button type="button" role="menuitem" wire:click="cancelMeeting('{{ $proxima->id }}', true)" wire:confirm="Cancelar esta reunião e todas as próximas da série? O cliente é avisado." class="flex w-full items-center rounded-lg px-3 py-2 text-left text-xs text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Cancelar esta e as próximas</button>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            @if ($outrasAgendadas->isNotEmpty())
                <div class="mt-5 border-t border-slate-100 pt-4 dark:border-white/10" x-data="{ todas: false }">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-300">Também marcadas ({{ $outrasAgendadas->count() }})</p>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($outrasAgendadas as $m)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-2.5" wire:key="outra-{{ $m->id }}" @if ($loop->index >= 3) x-show="todas" x-cloak @endif>
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-lg bg-slate-100 leading-none dark:bg-white/10">
                                        <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $m->scheduled_at->format('d') }}</span>
                                        <span class="mt-0.5 text-[9px] font-medium text-slate-500 uppercase dark:text-slate-400">{{ $m->scheduled_at->translatedFormat('M') }}</span>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm text-slate-800 first-letter:uppercase dark:text-slate-200">{{ $m->scheduled_at->translatedFormat('l') }} · {{ $m->scheduled_at->format('H:i') }}</span>
                                        <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $m->format->label() }} · {{ $m->duration_minutes }} min @if ($m->location && mb_strtolower(trim($m->location)) !== mb_strtolower($m->format->label())) · {{ $m->location }} @endif</span>
                                    </span>
                                </div>
                                @if ($ehConsultor)
                                    <div class="flex shrink-0 items-center gap-1">
                                        <button type="button" wire:click="openForm('{{ $m->id }}')" class="btn-ghost px-2 py-1 text-xs">Remarcar</button>
                                        <button type="button" wire:click="cancelMeeting('{{ $m->id }}')" wire:confirm="Cancelar esta reunião? O cliente é avisado." class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Cancelar</button>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($outrasAgendadas->count() > 3)
                        <button type="button" @click="todas = ! todas" class="btn-ghost mt-1 -ml-2 px-2 py-1 text-xs" x-text="todas ? 'Mostrar menos' : 'Ver todas ({{ $outrasAgendadas->count() }})'"></button>
                    @endif
                </div>
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
                @if (! $editingId)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Repetir</label>
                        <select wire:model.live="repeat" class="select mt-1.5 w-full">
                            <option value="none">Não repetir</option>
                            <option value="weekly">Toda semana</option>
                            <option value="biweekly">A cada 15 dias</option>
                            <option value="monthly">Todo mês</option>
                        </select>
                    </div>
                    @if ($repeat !== 'none')
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quantas reuniões no total</label>
                            <input type="number" wire:model="occurrences" min="2" max="12" class="input mt-1.5">
                            @error('occurrences') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @endif
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

    @include('livewire.engagement.partials.attach-modal')

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
