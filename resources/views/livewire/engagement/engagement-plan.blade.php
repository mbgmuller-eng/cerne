<section class="card p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Passo a passo</h2>
            @if ($etapas->isNotEmpty())
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $etapasFeitas }} de {{ $etapas->count() }} etapas concluídas · {{ $itensFeitos }} de {{ $totalItens }} itens</p>
            @endif
        </div>
        @if ($visitante->isConsultant())
            <button type="button" wire:click="toggleEditing" class="btn-secondary px-3 py-1.5 text-xs">{{ $editing ? 'Concluir edição' : 'Editar roteiro' }}</button>
        @endif
    </div>

    @if ($etapas->isNotEmpty())
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700" role="progressbar" aria-valuenow="{{ $totalItens > 0 ? (int) floor($itensFeitos / $totalItens * 100) : 0 }}" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-accent-600 dark:bg-accent-400" style="width: {{ $totalItens > 0 ? floor($itensFeitos / $totalItens * 100) : 0 }}%"></div>
        </div>
    @endif

    @if ($etapas->isEmpty())
        <p class="mt-4 text-sm text-slate-600 dark:text-slate-300">
            @if ($visitante->isConsultant())
                O roteiro deste cliente ainda está vazio. Aplique um modelo ou monte as etapas do zero.
            @else
                Seu consultor ainda não montou o passo a passo. Quando ele montar, você acompanha cada etapa por aqui.
            @endif
        </p>
    @else
        <ol class="mt-4 space-y-2">
            @foreach ($etapas as $indice => $etapa)
                @php
                    $feita = $etapa->isDone();
                    $comecou = ! $feita && $etapa->items->whereNotNull('done_at')->isNotEmpty();
                @endphp
                <li class="rounded-xl border border-slate-200 dark:border-white/10" wire:key="etapa-{{ $etapa->id }}" x-data="{ aberta: {{ $comecou ? 'true' : 'false' }} }">
                    <div class="flex items-center gap-2 px-3 py-2.5">
                        <button type="button" @click="aberta = !aberta" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                            <span @class([
                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-medium',
                                'bg-accent-600 text-white dark:bg-accent-500' => $feita,
                                'bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300' => $comecou,
                                'bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400' => ! $feita && ! $comecou,
                            ])>{{ $feita ? '✓' : $indice + 1 }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $etapa->title }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $etapa->items->whereNotNull('done_at')->count() }} de {{ $etapa->items->count() }} itens</span>
                            </span>
                        </button>
                        @if ($editing)
                            <span class="flex shrink-0 items-center gap-1">
                                <button type="button" wire:click="moveStage('{{ $etapa->id }}', -1)" class="btn-ghost px-2 py-1 text-xs" aria-label="Subir etapa">↑</button>
                                <button type="button" wire:click="moveStage('{{ $etapa->id }}', 1)" class="btn-ghost px-2 py-1 text-xs" aria-label="Descer etapa">↓</button>
                                <button type="button" wire:click="openStage('{{ $etapa->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                                <button type="button" wire:click="deleteStage('{{ $etapa->id }}')" wire:confirm="Apagar esta etapa e os itens dela?" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Apagar</button>
                            </span>
                        @endif
                    </div>

                    <div x-show="aberta || {{ $editing ? 'true' : 'false' }}" x-cloak class="border-t border-slate-100 px-3 py-2 dark:border-white/10">
                        @if ($etapa->description)
                            <p class="pb-1 text-xs text-slate-500 dark:text-slate-400">{{ $etapa->description }}</p>
                        @endif
                        <ul class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($etapa->items as $item)
                                <li class="flex items-center justify-between gap-3 py-2" wire:key="item-{{ $item->id }}">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        @if ($visitante->isConsultant() && ! $item->isAuto())
                                            <input type="checkbox" class="rounded border-slate-300" @checked($item->done_at) wire:click="setDone('{{ $item->id }}', {{ $item->done_at ? 'false' : 'true' }})" aria-label="Marcar como feito">
                                        @else
                                            <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border text-[10px] {{ $item->done_at ? 'border-accent-600 bg-accent-600 text-white dark:border-accent-500 dark:bg-accent-500' : 'border-slate-300 text-transparent dark:border-slate-600' }}" aria-hidden="true">✓</span>
                                        @endif
                                        <span @class(['min-w-0 text-sm', 'text-slate-400 line-through decoration-slate-300' => $item->done_at, 'text-slate-700 dark:text-slate-300' => ! $item->done_at])>{{ $item->title }}</span>
                                    </div>
                                    <span class="flex shrink-0 items-center gap-1.5">
                                        @if ($item->isAuto())
                                            <span class="badge bg-slate-100 text-slate-500 dark:bg-white/10 dark:text-slate-400" title="{{ $criterios[$item->auto_check] ?? '' }}">automático</span>
                                        @endif
                                        @if ($editing)
                                            <button type="button" wire:click="openItem('{{ $etapa->id }}', '{{ $item->id }}')" class="btn-ghost px-2 py-0.5 text-xs">Editar</button>
                                            <button type="button" wire:click="deleteItem('{{ $item->id }}')" wire:confirm="Apagar este item?" class="btn-ghost px-2 py-0.5 text-xs text-red-700 dark:text-red-400">Apagar</button>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($editing)
                            <button type="button" wire:click="openItem('{{ $etapa->id }}')" class="btn-ghost mt-1 px-2 py-1 text-xs">+ Item</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($visitante->isConsultant() && $editing)
        <div class="mt-4 space-y-3 border-t border-slate-100 pt-4 dark:border-white/10">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="openStage" class="btn-secondary px-3 py-1.5 text-xs">+ Etapa</button>
                @if ($etapas->isNotEmpty())
                    <button type="button" wire:click="openSaveTemplate" class="btn-ghost px-3 py-1.5 text-xs">Salvar como meu modelo</button>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model="templateChoice" class="select w-auto py-1 text-xs">
                    <option value="default">Roteiro padrão do Cerne</option>
                    @foreach ($modelos as $m)
                        <option value="{{ $m->id }}">{{ $m->name }}</option>
                    @endforeach
                </select>
                <button type="button" wire:click="applyTemplate" class="btn-secondary px-3 py-1.5 text-xs">Aplicar modelo</button>
                <a href="{{ route('consultant.plan-templates') }}" class="text-xs text-slate-500 underline dark:text-slate-400">Gerenciar modelos</a>
            </div>
            @if ($templateError)
                <p class="text-xs text-red-700 dark:text-red-400">{{ $templateError }}</p>
            @endif
            <p class="text-xs text-slate-400">Aplicar um modelo acrescenta as etapas dele ao fim do roteiro. O roteiro do cliente é uma cópia: mudar o modelo depois não o altera.</p>
        </div>
    @endif

    {{-- Etapa --}}
    <x-modal wire-model="showStage">
        <form wire:submit="saveStage" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $stageId ? 'Editar etapa' : 'Nova etapa' }}</h2>
                <button type="button" wire:click="$set('showStage', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome da etapa</label>
                <input type="text" wire:model="stageTitle" maxlength="120" class="input mt-1.5" placeholder="Ex.: Reserva de emergência">
                @error('stageTitle') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Descrição <span class="font-normal text-slate-400">(opcional)</span></label>
                <textarea wire:model="stageDescription" rows="2" maxlength="1000" class="input mt-1.5"></textarea>
                @error('stageDescription') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button></div>
        </form>
    </x-modal>

    {{-- Item --}}
    <x-modal wire-model="showItem">
        <form wire:submit="saveItem" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $itemId ? 'Editar item' : 'Novo item' }}</h2>
                <button type="button" wire:click="$set('showItem', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que precisa estar feito</label>
                <input type="text" wire:model="itemTitle" maxlength="160" class="input mt-1.5" placeholder="Ex.: Reserva de paz completa">
                @error('itemTitle') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Como marcar</label>
                <select wire:model="itemAuto" class="select mt-1.5 w-full">
                    <option value="">Manualmente, por mim</option>
                    @foreach ($criterios as $chave => $rotulo)
                        <option value="{{ $chave }}">Automático: {{ $rotulo }}</option>
                    @endforeach
                </select>
                @error('itemAuto') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button></div>
        </form>
    </x-modal>

    {{-- Salvar como modelo --}}
    <x-modal wire-model="showSaveTemplate">
        <form wire:submit="saveTemplate" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Salvar como meu modelo</h2>
                <button type="button" wire:click="$set('showSaveTemplate', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400">Guarda só as etapas e os itens, sem o que já foi marcado nem dados do cliente. Só você vê os seus modelos.</p>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do modelo</label>
                <input type="text" wire:model="templateName" maxlength="120" class="input mt-1.5" placeholder="Ex.: Casal com filhos pequenos">
                @error('templateName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar modelo</button></div>
        </form>
    </x-modal>
</section>
