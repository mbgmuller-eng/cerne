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

    {{-- Busca ---------------------------------------------------------- --}}
    <div class="flex flex-wrap items-end gap-3 card p-4">
        <div class="min-w-48 flex-1">
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Buscar</label>
            <input type="text" wire:model.live.debounce.400ms="search" class="input mt-1.5" placeholder="Nome ou e-mail">
        </div>
    </div>

    {{-- Quadro — sem arrastar-e-soltar de propósito: os botões "Avançar/Voltar"
         funcionam igual no celular. --}}
    <div class="grid gap-4 lg:grid-cols-3">
        @foreach ($openStages as $stageCase)
            @php $leadsDaColuna = $leadsByStage->get($stageCase->value, collect()); @endphp
            <div class="space-y-3">
                <div class="flex items-center gap-2 px-1">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $stageCase->color() }}"></span>
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $stageCase->label() }}</h2>
                    <span class="text-xs text-slate-400">({{ $leadsDaColuna->count() }})</span>
                </div>

                @if ($leadsDaColuna->isEmpty())
                    <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-4 py-8 text-center">
                        <p class="text-xs text-slate-400">Nenhum contato aqui.</p>
                    </div>
                @else
                    @foreach ($leadsDaColuna as $lead)
                        @include('livewire.consultant.partials.lead-card', ['lead' => $lead])
                    @endforeach
                @endif
            </div>
        @endforeach
    </div>

    {{-- Convertidos e perdidos --------------------------------------------- --}}
    <div>
        <button type="button" wire:click="$toggle('showClosed')" class="text-sm text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200">
            {{ $showClosed ? '− Ocultar' : '+ Ver' }} convertidos e perdidos
        </button>

        @if ($showClosed)
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($closedLeads as $lead)
                    @include('livewire.consultant.partials.lead-card', ['lead' => $lead])
                @empty
                    <p class="text-sm text-slate-400 sm:col-span-2 lg:col-span-3">Nenhum contato convertido ou perdido ainda.</p>
                @endforelse
            </div>
        @endif
    </div>

</div>
