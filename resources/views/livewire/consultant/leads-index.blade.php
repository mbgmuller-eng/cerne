@use('App\Support\Money')
@use('App\Enums\LeadKind')

@php
    $campo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
    $erro = 'mt-1 text-xs text-red-700 dark:text-red-400';
    $seguros = $kind === LeadKind::Insurance;
@endphp

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Leads</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Contatos que ainda não são clientes.</p>
        </div>

        <button wire:click="toggleLeadForm" class="btn-primary px-3 py-1.5">+ Novo contato</button>
    </div>

    {{-- Funis: consultoria e seguros. Corretor só trabalha com seguros. ----------------- --}}
    @unless ($ehCorretor)
        <div class="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-white/5" role="tablist" aria-label="Funil">
            @foreach (['consultoria' => 'Consultoria', 'seguros' => 'Seguros'] as $chave => $rotulo)
                <button
                    type="button"
                    role="tab"
                    wire:click="setAba('{{ $chave }}')"
                    aria-selected="{{ $aba === $chave ? 'true' : 'false' }}"
                    @class([
                        'flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' => $aba === $chave,
                        'text-slate-500 dark:text-slate-400' => $aba !== $chave,
                    ])
                >{{ $rotulo }} <span class="text-xs font-normal text-slate-400">({{ $abaCounts[$chave] }})</span></button>
            @endforeach
        </div>
    @endunless

    {{-- Valor do funil de seguros --------------------------------------------------- --}}
    @if ($funnelValue !== null)
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4">
                <p class="eyebrow">Prêmio previsto no funil</p>
                <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ Money::format($funnelValue['premio_anual']) }}<span class="text-xs font-normal text-slate-400"> por ano</span></p>
                <p class="mt-1 text-xs text-slate-400">{{ $funnelValue['com_cotacao'] }} {{ $funnelValue['com_cotacao'] === 1 ? 'contato com cotação' : 'contatos com cotação' }}. Em cada um conta a cotação aceita ou a mais barata ainda válida.</p>
            </div>
            <div class="card p-4">
                <p class="eyebrow">Comissão prevista (1º ano)</p>
                <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ Money::format($funnelValue['comissao']) }}</p>
                <p class="mt-1 text-xs text-slate-400">Só das cotações com comissão informada.</p>
            </div>
            <div class="card p-4">
                <p class="eyebrow">Ainda sem cotação</p>
                <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ $funnelValue['sem_cotacao'] }}</p>
                <p class="mt-1 text-xs text-slate-400">Contatos em andamento sem prêmio estimado.</p>
            </div>
        </div>
    @endif

    {{-- Novo/editar lead --------------------------------------------- --}}
    <x-modal wire-model="showLeadForm" max-width="2xl">
        <form wire:submit="saveLead" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingLeadId ? 'Editar contato' : 'Novo contato' }}</h2>
                <button type="button" wire:click="toggleLeadForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                @unless ($ehCorretor)
                    <div class="@sm:col-span-2">
                        <label class="{{ $campo }}">Funil</label>
                        <select wire:model.live="leadKind" class="select mt-1.5 w-full">
                            @foreach ($kinds as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('leadKind') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    </div>
                @endunless

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Nome</label>
                    <input type="text" wire:model="leadName" class="input mt-1.5" placeholder="Nome do contato">
                    @error('leadName') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">E-mail</label>
                    <input type="email" wire:model="leadEmail" class="input mt-1.5" placeholder="email@exemplo.com">
                    @error('leadEmail') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">Necessário para converter em cliente depois.</p>
                </div>

                <div>
                    <label class="{{ $campo }}">Telefone</label>
                    <input type="text" wire:model="leadPhone" class="input mt-1.5" placeholder="(11) 90000-0000">
                    @error('leadPhone') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="{{ $campo }}">Data de nascimento (opcional)</label>
                    <input type="date" wire:model="leadBirthDate" class="input mt-1.5">
                    @error('leadBirthDate') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">Entra nos avisos de aniversário e serve para cotar vida e saúde.</p>
                </div>

                <div>
                    <label class="{{ $campo }}">Origem (opcional)</label>
                    <select wire:model="leadSource" class="select mt-1.5 w-full">
                        <option value="">Não informada</option>
                        @foreach ($sources as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('leadSource') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Quem indicou ou qual evento (opcional)</label>
                    <input type="text" wire:model="leadSourceDetail" class="input mt-1.5" placeholder="Ex.: indicado pela Ana Souza; Feira de Seguros 2026">
                    @error('leadSourceDetail') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                {{-- O que se precisa para cotar: só no funil de seguros. ------------------------------ --}}
                @if ($leadKind === LeadKind::Insurance->value)
                    <div class="@sm:col-span-2 space-y-3 rounded-lg border border-slate-100 p-3 dark:border-white/10" wire:key="lead-interests">
                        <p class="text-xs font-semibold text-slate-700 dark:text-slate-300">Seguros de interesse</p>
                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                            @foreach ($insuranceTypes as $valor => $rotulo)
                                <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-400">
                                    <input type="checkbox" wire:model.live="leadInterests" value="{{ $valor }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    {{ $rotulo }}
                                </label>
                            @endforeach
                        </div>
                        @error('leadInterests.*') <p class="{{ $erro }}">{{ $message }}</p> @enderror

                        @foreach ($insuranceTypes as $valor => $rotulo)
                            @if (in_array($valor, $leadInterests, true) && ! empty($quoteFields[$valor]))
                                <div class="space-y-2 border-t border-slate-100 pt-3 dark:border-white/10" wire:key="dados-{{ $valor }}">
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Para cotar {{ mb_strtolower($rotulo) }}</p>
                                    <div class="grid gap-3 @sm:grid-cols-3">
                                        @foreach ($quoteFields[$valor] as $dado)
                                            <div>
                                                <label class="{{ $campo }}">{{ $dado['label'] }}</label>
                                                @if ($dado['type'] === 'select')
                                                    <select wire:model="leadQuoteData.{{ $valor }}.{{ $dado['key'] }}" class="select mt-1.5 w-full">
                                                        <option value="">—</option>
                                                        @foreach ($dado['options'] as $v => $r)
                                                            <option value="{{ $v }}">{{ $r }}</option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <input type="{{ $dado['type'] }}" @if ($dado['type'] === 'number') step="0.01" min="0" @endif wire:model="leadQuoteData.{{ $valor }}.{{ $dado['key'] }}" class="input mt-1.5">
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Próximo contato (opcional)</label>
                    <input type="datetime-local" wire:model="leadNextActionAt" class="input mt-1.5">
                    @error('leadNextActionAt') <p class="{{ $erro }}">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="{{ $campo }}">Notas (opcional)</label>
                    <textarea wire:model="leadNotes" rows="2" class="input mt-1.5"></textarea>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">{{ $editingLeadId ? 'Salvar alterações' : 'Salvar contato' }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Cotações e anexos do lead -------------------------------------- --}}
    <x-modal wire-model="showQuotes" max-width="{{ $showComparison ? '4xl' : '2xl' }}">
        @if ($quotesLead)
            @include('livewire.consultant.partials.lead-quotes', ['lead' => $quotesLead])
        @endif
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
    {{-- Uma linha só a partir do tablet: as colunas dividem a largura e, quando não cabem com folga, a linha rola
         para o lado em vez de quebrar em duas. No celular ficam empilhadas. --}}
    <div class="space-y-6 md:grid md:grid-flow-col md:auto-cols-[minmax(12rem,1fr)] md:gap-4 md:space-y-0 md:overflow-x-auto md:pb-4">
        @foreach ($openStages as $stageCase)
            @php $leadsDaColuna = $leadsByStage->get($stageCase->value, collect()); @endphp
            <div class="min-w-0 space-y-3">
                <div class="flex items-center gap-2 px-1">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $stageCase->color() }}"></span>
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $stageCase->labelFor($kind) }}</h2>
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
            {{ $showClosed ? '− Ocultar' : '+ Ver' }} {{ $seguros ? 'emitidos' : 'convertidos' }} e perdidos
        </button>

        @if ($showClosed)
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($closedLeads as $lead)
                    @include('livewire.consultant.partials.lead-card', ['lead' => $lead])
                @empty
                    <p class="text-sm text-slate-400 sm:col-span-2 lg:col-span-3">Nenhum contato {{ $seguros ? 'emitido' : 'convertido' }} ou perdido ainda.</p>
                @endforelse
            </div>
        @endif
    </div>

</div>
