@use('App\Enums\LeadStage')

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Leads</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Contatos que ainda não são clientes.</p>
        </div>

        <button wire:click="toggleLeadForm" class="btn-primary px-3 py-1.5">+ Novo contato</button>
    </div>

    {{-- Novo/editar lead --------------------------------------------- --}}
    <x-modal wire-model="showLeadForm">
        <form wire:submit="saveLead" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingLeadId ? 'Editar contato' : 'Novo contato' }}</h2>
                <button type="button" wire:click="toggleLeadForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="leadName" class="input mt-1.5" placeholder="Nome do contato">
                    @error('leadName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">E-mail</label>
                    <input type="email" wire:model="leadEmail" class="input mt-1.5" placeholder="email@exemplo.com">
                    @error('leadEmail') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">Necessário pra converter em cliente depois.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Telefone</label>
                    <input type="text" wire:model="leadPhone" class="input mt-1.5" placeholder="(11) 90000-0000">
                    @error('leadPhone') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Próximo contato (opcional)</label>
                    <input type="datetime-local" wire:model="leadNextActionAt" class="input mt-1.5">
                    @error('leadNextActionAt') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Notas (opcional)</label>
                    <textarea wire:model="leadNotes" rows="2" class="input mt-1.5"></textarea>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">{{ $editingLeadId ? 'Salvar alterações' : 'Salvar contato' }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Filtros ---------------------------------------------------------- --}}
    <div class="flex flex-wrap items-end gap-3 card p-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Estágio</label>
            <select wire:model.live="stage" class="select mt-1.5">
                <option value="">Todos</option>
                @foreach ($stages as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>

        <div class="min-w-48 flex-1">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Buscar</label>
            <input type="text" wire:model.live.debounce.400ms="search" class="input mt-1.5" placeholder="Nome ou e-mail">
        </div>
    </div>

    {{-- Lista ------------------------------------------------------------ --}}
    @if ($leads->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum contato neste recorte.</p>
            <p class="mt-1 text-xs text-slate-400">Um lead vira cliente de verdade quando você converte — o convite de sempre é enviado na hora.</p>
        </div>
    @else
        <ul class="card divide-y divide-slate-100 dark:divide-white/10">
            @foreach ($leads as $lead)
                <li class="px-5 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $lead->stage->color() }}"></span>
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $lead->name }}</p>
                                <span @class([
                                    'badge shrink-0',
                                    'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $lead->stage === LeadStage::NewContact,
                                    'bg-sky-100 text-sky-900 dark:bg-sky-500/15 dark:text-sky-300' => $lead->stage === LeadStage::MeetingScheduled,
                                    'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300' => $lead->stage === LeadStage::ProposalSent,
                                    'bg-brand-100 text-brand-900 dark:bg-brand-500/20 dark:text-brand-100' => $lead->stage === LeadStage::Converted,
                                    'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300' => $lead->stage === LeadStage::Lost,
                                ])>{{ $lead->stage->label() }}</span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
                                @if ($lead->email) {{ $lead->email }} @endif
                                @if ($lead->phone) · {{ $lead->phone }} @endif
                                @if ($lead->next_action_at) · próximo contato {{ $lead->next_action_at->format('d/m \à\s H:i') }} @endif
                            </p>

                            @if ($lead->activities->isNotEmpty())
                                <ul class="mt-2 space-y-0.5">
                                    @foreach ($lead->activities as $atividade)
                                        <li class="text-xs text-slate-400">
                                            {{ $atividade->type->label() }} · {{ $atividade->occurred_at->format('d/m') }}
                                            @if ($atividade->description) — {{ $atividade->description }} @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($lead->stage === LeadStage::Lost && $lead->lost_reason)
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">Perdido: {{ $lead->lost_reason }}</p>
                            @endif
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @unless ($lead->stage->isClosed())
                                <button wire:click="convertLead('{{ $lead->id }}')" class="btn-primary px-3 py-1.5 whitespace-nowrap">
                                    Converter em cliente
                                </button>
                                <button type="button" wire:click="toggleLogActivity('{{ $lead->id }}')" class="btn-secondary px-3 py-1.5 whitespace-nowrap">
                                    {{ $loggingActivityLeadId === $lead->id ? 'Cancelar' : 'Registrar contato' }}
                                </button>
                                <button type="button" wire:click="toggleMarkLost('{{ $lead->id }}')" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300 whitespace-nowrap">
                                    {{ $markingLostLeadId === $lead->id ? 'Cancelar' : 'Marcar perdido' }}
                                </button>
                            @endunless

                            @if ($confirmingDeleteLeadId === $lead->id)
                                <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
                                <button wire:click="deleteLead('{{ $lead->id }}')" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
                                <button wire:click="cancelDeleteLead" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
                            @else
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button type="button" @click="open = !open" class="btn-ghost px-2 py-1.5" aria-label="Mais ações">
                                        <x-nav-icon name="dots" class="h-4 w-4" />
                                    </button>
                                    <div x-show="open" x-transition x-cloak @click="open = false" class="absolute right-0 z-10 mt-1 w-32 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10">
                                        <button wire:click="editLead('{{ $lead->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                                        <button wire:click="confirmDeleteLead('{{ $lead->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Excluir</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Registrar contato --------------------------------- --}}
                    @if ($loggingActivityLeadId === $lead->id)
                        <form wire:submit="logActivity" class="mt-3 grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-[auto_auto_1fr_auto] dark:bg-slate-800/60">
                            <div>
                                <select wire:model="activityType" class="select py-1.5 text-xs">
                                    @foreach ($activityTypes as $valor => $rotulo)
                                        <option value="{{ $valor }}">{{ $rotulo }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <input type="datetime-local" wire:model="activityOccurredAt" class="input py-1.5 text-xs">
                            </div>
                            <div>
                                <input type="text" wire:model="activityDescription" placeholder="O que foi conversado (opcional)" class="input py-1.5 text-xs w-full">
                            </div>
                            <div>
                                <button type="submit" class="btn-primary px-3 py-1.5 text-xs whitespace-nowrap" wire:loading.attr="disabled">Salvar</button>
                            </div>
                            @error('activityOccurredAt') <p class="text-xs text-red-700 dark:text-red-400 sm:col-span-4">{{ $message }}</p> @enderror
                        </form>
                    @endif

                    {{-- Marcar perdido -------------------------------------- --}}
                    @if ($markingLostLeadId === $lead->id)
                        <form wire:submit="markLost" class="mt-3 flex flex-wrap items-start gap-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-800/60">
                            <div class="flex-1">
                                <input type="text" wire:model="lostReason" placeholder="Por que não avançou?" class="input py-1.5 text-xs w-full">
                                @error('lostReason') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn-primary px-3 py-1.5 text-xs whitespace-nowrap" wire:loading.attr="disabled">Confirmar</button>
                        </form>
                    @endif

                    {{-- Resultado da conversão ------------------------------ --}}
                    @if ($lastConvertedLeadId === $lead->id && $lastInviteLink)
                        <div class="mt-3 rounded-lg bg-brand-50 p-3 dark:bg-brand-500/10">
                            <p class="text-xs font-medium text-brand-900 dark:text-brand-200">Convite enviado — link de cadastro:</p>
                            <p class="mt-1 font-mono text-xs break-all text-brand-800 dark:text-brand-300">{{ $lastInviteLink }}</p>
                        </div>
                    @endif

                    @error('convert') <p class="mt-2 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </li>
            @endforeach
        </ul>
    @endif

</div>
