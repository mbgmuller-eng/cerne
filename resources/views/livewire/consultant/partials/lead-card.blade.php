@use('App\Enums\LeadStage')

{{--
    Cartão de um lead — usado tanto nas colunas do quadro quanto na lista
    de convertidos/perdidos. Recebe $lead do @include; o resto ($loggingActivityLeadId
    etc.) já está no escopo do componente pai.
--}}
<div class="card space-y-2 p-3">
    <div class="min-w-0">
        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $lead->name }}</p>
        <p class="mt-0.5 truncate text-xs text-slate-500 dark:text-slate-400">
            @if ($lead->email) {{ $lead->email }} @endif
            @if ($lead->phone) · {{ $lead->phone }} @endif
        </p>
        @if ($lead->next_action_at)
            <p class="mt-0.5 truncate text-xs text-accent-600 dark:text-accent-400">próximo contato {{ $lead->next_action_at->format('d/m \à\s H:i') }}</p>
        @endif

        @if ($lead->activities->isNotEmpty())
            <ul class="mt-1.5 space-y-0.5">
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

    <div class="flex flex-wrap items-center gap-1.5">
        @unless ($lead->stage->isClosed())
            @if ($lead->stage !== LeadStage::NewContact)
                <button type="button" wire:click="regressStage('{{ $lead->id }}')" class="btn-ghost px-2 py-1 text-xs" title="Voltar">←</button>
            @endif
            @if ($lead->stage !== LeadStage::ProposalSent)
                <button type="button" wire:click="advanceStage('{{ $lead->id }}')" class="btn-ghost px-2 py-1 text-xs" title="Avançar">Avançar →</button>
            @endif

            <button type="button" wire:click="toggleLogActivity('{{ $lead->id }}')" class="btn-secondary px-2 py-1 text-xs whitespace-nowrap">
                {{ $loggingActivityLeadId === $lead->id ? 'Cancelar' : 'Registrar' }}
            </button>
            <button wire:click="convertLead('{{ $lead->id }}')" class="btn-primary px-2 py-1 text-xs whitespace-nowrap">
                Converter
            </button>
        @endunless

        @if ($confirmingDeleteLeadId === $lead->id)
            <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
            <button wire:click="deleteLead('{{ $lead->id }}')" class="text-xs font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
            <button wire:click="cancelDeleteLead" class="text-xs text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
        @else
            <div class="relative ml-auto" x-data="{ open: false }" @click.outside="open = false">
                <button type="button" @click="open = !open" class="btn-ghost px-1.5 py-1" aria-label="Mais ações">
                    <x-nav-icon name="dots" class="h-4 w-4" />
                </button>
                <div x-show="open" x-transition x-cloak @click="open = false" class="absolute right-0 z-10 mt-1 w-36 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10">
                    <button wire:click="editLead('{{ $lead->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                    @unless ($lead->stage->isClosed())
                        <button wire:click="toggleMarkLost('{{ $lead->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Marcar perdido</button>
                    @endunless
                    <button wire:click="confirmDeleteLead('{{ $lead->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Excluir</button>
                </div>
            </div>
        @endif
    </div>

    {{-- Registrar contato --------------------------------------------- --}}
    @if ($loggingActivityLeadId === $lead->id)
        <form wire:submit="logActivity" class="space-y-2 rounded-lg bg-slate-50 p-2.5 dark:bg-slate-800/60">
            <select wire:model="activityType" class="select w-full py-1.5 text-xs">
                @foreach ($activityTypes as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <input type="datetime-local" wire:model="activityOccurredAt" class="input w-full py-1.5 text-xs">
            <input type="text" wire:model="activityDescription" placeholder="O que foi conversado (opcional)" class="input w-full py-1.5 text-xs">
            @error('activityOccurredAt') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full px-2 py-1.5 text-xs" wire:loading.attr="disabled">Salvar</button>
        </form>
    @endif

    {{-- Marcar perdido ---------------------------------------------------- --}}
    @if ($markingLostLeadId === $lead->id)
        <form wire:submit="markLost" class="space-y-2 rounded-lg bg-slate-50 p-2.5 dark:bg-slate-800/60">
            <input type="text" wire:model="lostReason" placeholder="Por que não avançou?" class="input w-full py-1.5 text-xs">
            @error('lostReason') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full px-2 py-1.5 text-xs" wire:loading.attr="disabled">Confirmar</button>
        </form>
    @endif

    {{-- Resultado da conversão --------------------------------------------- --}}
    @if ($lastConvertedLeadId === $lead->id && $lastInviteLink)
        <div class="rounded-lg bg-brand-50 p-2.5 dark:bg-brand-500/10">
            <p class="text-xs font-medium text-brand-900 dark:text-brand-200">Convite enviado — link de cadastro:</p>
            <p class="mt-1 font-mono text-xs break-all text-brand-800 dark:text-brand-300">{{ $lastInviteLink }}</p>
        </div>
    @endif

    @error('convert') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
</div>
