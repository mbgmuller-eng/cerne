<div class="space-y-6">

    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Cuidados e itens</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Aparelho auditivo, palmilha, óculos, próteses e meias de compressão: de quanto em quanto tempo trocar, a última vez e a próxima.
                Avisamos um dia antes e no dia. Remédios de uso contínuo ficam na Ficha de Saúde.
            </p>
        </div>
        <button type="button" wire:click="newItem" class="btn-primary shrink-0 px-3 py-2 text-sm">+ Novo item</button>
    </div>

    <section class="card space-y-3 p-5">
        <p class="eyebrow">Em uso</p>
        @if ($ativos->isEmpty())
            <p class="text-xs text-slate-400">Nenhum item cadastrado. Exemplo: filtro do aparelho auditivo, a cada 15 dias.</p>
        @else
            <ul class="space-y-2">
                @foreach ($ativos as $item)
                    @php
                        $dias = $item->daysUntilDue($hoje);
                    @endphp
                    <li class="rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="item-{{ $item->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                    {{ $item->name }}
                                    <span class="ml-1 rounded-full bg-saude-200/40 px-2 py-0.5 text-[10px] font-medium text-saude-800 dark:bg-saude-800/30 dark:text-saude-200">{{ $item->category->label() }}</span>
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    {{ $item->member->name }}@if ($item->device_name) · {{ $item->device_name }}@endif · {{ $item->frequencyLabel() }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    @if ($item->last_done_on)
                                        Última vez em {{ $item->last_done_on->format('d/m/Y') }} ·
                                    @endif
                                    Próxima em {{ $item->next_due_on?->format('d/m/Y') ?? 'sem data' }}
                                    @if ($dias !== null)
                                        <span @class([
                                            'ml-1 font-medium',
                                            'text-red-700 dark:text-red-400' => $dias < 0,
                                            'text-amber-700 dark:text-amber-400' => $dias >= 0 && $dias <= 1,
                                            'text-slate-500 dark:text-slate-400' => $dias > 1,
                                        ])>
                                            @if ($dias < 0)
                                                (atrasado há {{ abs($dias) }} {{ abs($dias) === 1 ? 'dia' : 'dias' }})
                                            @elseif ($dias === 0)
                                                (hoje)
                                            @elseif ($dias === 1)
                                                (amanhã)
                                            @else
                                                (em {{ $dias }} dias)
                                            @endif
                                        </span>
                                    @endif
                                </p>
                                @if ($item->notes)
                                    <p class="mt-1 whitespace-pre-line text-xs text-slate-500 dark:text-slate-400">{{ $item->notes }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <button type="button" wire:click="markDone('{{ $item->id }}')" wire:loading.attr="disabled" class="btn-secondary px-3 py-1 text-xs">Feito hoje</button>
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="editItem('{{ $item->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                                    <button type="button" wire:click="toggleActive('{{ $item->id }}')" class="text-xs text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">pausar</button>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($pausados->isNotEmpty())
        <section class="card space-y-3 p-5">
            <p class="eyebrow">Pausados</p>
            <p class="text-xs text-slate-400">Sem avisos. Ao retomar, a contagem recomeça de hoje.</p>
            <ul class="space-y-2">
                @foreach ($pausados as $item)
                    <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="pausado-{{ $item->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ $item->name }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $item->member->name }} · {{ $item->frequencyLabel() }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <button type="button" wire:click="toggleActive('{{ $item->id }}')" class="btn-secondary px-3 py-1 text-xs">Retomar</button>
                            <button type="button" wire:click="delete('{{ $item->id }}')" wire:confirm="Remover este item de vez?" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">remover</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-modal wire-model="showForm">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExisting ? 'Editar item' : 'Novo item' }}</h2>
                <button type="button" wire:click="closeForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa</label>
                    <select wire:model="memberId" class="select mt-1.5 w-full">
                        @foreach ($membros as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                    @error('memberId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
                    <select wire:model="category" class="select mt-1.5 w-full">
                        @foreach ($categorias as $categoria)
                            <option value="{{ $categoria->value }}">{{ $categoria->label() }}</option>
                        @endforeach
                    </select>
                    @error('category') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que é trocado ou revisado</label>
                    <input type="text" wire:model="name" class="input mt-1.5" placeholder="Ex.: Filtro, Palmilha ortopédica, Lentes" maxlength="120">
                    @error('name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Aparelho ou produto <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="text" wire:model="deviceName" class="input mt-1.5" placeholder="Ex.: Aparelho auditivo direito" maxlength="120">
                    @error('deviceName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">A cada</label>
                    <input type="number" wire:model.live.debounce.300ms="intervalValue" min="1" max="365" class="input mt-1.5" placeholder="15">
                    @error('intervalValue') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Unidade</label>
                    <select wire:model.live="intervalUnit" class="select mt-1.5 w-full">
                        @foreach ($unidades as $unidade)
                            <option value="{{ $unidade->value }}">{{ $unidade->label() }}</option>
                        @endforeach
                    </select>
                    @error('intervalUnit') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Última vez que foi trocado ou revisado</label>
                    <input type="date" wire:model.live="lastDoneOn" max="{{ $hoje->toDateString() }}" class="input mt-1.5">
                    @error('lastDoneOn') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    @if ($this->proximaPrevista)
                        <p class="mt-1.5 text-xs text-saude-800 dark:text-saude-200">Próxima vez prevista: {{ $this->proximaPrevista->format('d/m/Y') }}</p>
                    @endif
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações <span class="font-normal text-slate-400">(opcional)</span></label>
                    <textarea wire:model="notes" class="input mt-1.5" rows="2" placeholder="Ex.: comprar na loja X, modelo Y"></textarea>
                    @error('notes') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="btn-primary flex-1">Salvar</button>
                @if ($editingExisting)
                    <button type="button" wire:click="delete('{{ $editingId }}')" wire:confirm="Remover este item de vez?" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">remover</button>
                @endif
            </div>
        </form>
    </x-modal>
</div>
