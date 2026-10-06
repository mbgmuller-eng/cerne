<div class="space-y-8">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Exercícios</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $total }} {{ $total === 1 ? 'exercício' : 'exercícios' }} no catálogo · {{ $semFoto }} sem foto · {{ $this->sugestoes->count() }} {{ $this->sugestoes->count() === 1 ? 'sugestão pendente' : 'sugestões pendentes' }}
            </p>
        </div>
        <button type="button" wire:click="novo" class="btn-primary">Novo exercício</button>
    </div>

    <div class="space-y-2">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Sugestões pendentes</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Exercícios que alguém digitou no próprio treino e que o catálogo não tem. A fila é anônima de propósito:
            treino é dado de Saúde, então aparece só o nome e quantas vezes foi pedido, nunca quem pediu.
            Aprovar abre o cadastro já preenchido para escolher o equipamento e subir as fotos.
        </p>

        <div class="card overflow-hidden p-0">
            @if ($this->sugestoes->isEmpty())
                <p class="px-5 py-4 text-sm text-slate-400 dark:text-slate-500">Nenhuma sugestão pendente no momento.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-2 font-medium">Exercício</th>
                            <th class="px-5 py-2 font-medium">Grupo</th>
                            <th class="px-5 py-2 font-medium">Tipo</th>
                            <th class="px-5 py-2 font-medium">Pedidos</th>
                            <th class="px-5 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($this->sugestoes as $sugestao)
                            <tr wire:key="sugestao-{{ $sugestao->id }}">
                                <td class="px-5 py-2.5 text-slate-800 dark:text-slate-200">{{ $sugestao->name }}</td>
                                <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $sugestao->muscle_group->label() }}</td>
                                <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">{{ $sugestao->measure_type->label() }}</td>
                                <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">pedido {{ $sugestao->times_suggested }} {{ $sugestao->times_suggested === 1 ? 'vez' : 'vezes' }}</td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                    <button type="button" wire:click="aprovarSugestao('{{ $sugestao->id }}')" class="text-sm font-medium text-accent-700 hover:underline dark:text-accent-400">Aprovar</button>
                                    <button type="button" wire:click="dispensarSugestao('{{ $sugestao->id }}')" class="ml-3 text-sm text-slate-500 hover:underline dark:text-slate-400">Dispensar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Catálogo</h2>
            <input type="search" wire:model.live.debounce.300ms="busca" class="input max-w-xs" placeholder="Buscar exercício" aria-label="Buscar exercício">
            <label class="inline-flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" wire:model.live="somenteSemFoto" class="rounded border-slate-300 dark:border-white/20">
                Só sem foto
            </label>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Quem escolhe um exercício do catálogo recebe uma cópia da foto. Editar ou excluir aqui não muda o treino de ninguém.
            Com os dois quadros (início e fim do movimento) a foto anima no treino.
        </p>

        @forelse ($grupos as $linha)
            <div class="card p-0" wire:key="grupo-{{ $linha['grupo']->value }}">
                <h3 class="border-b border-slate-100 px-5 py-2.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-white/10 dark:text-slate-400">
                    {{ $linha['grupo']->label() }} <span class="font-normal normal-case">({{ $linha['exercicios']->count() }})</span>
                </h3>
                <div class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($linha['exercicios'] as $exercicio)
                        <div class="flex items-center gap-3 px-5 py-2.5" wire:key="exercicio-{{ $exercicio->id }}">
                            @if ($exercicio->imageUrl())
                                <img src="{{ $exercicio->imageUrl() }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-white/10">
                            @else
                                <span class="h-10 w-10 shrink-0 rounded-lg bg-slate-100 ring-1 ring-slate-200 dark:bg-white/5 dark:ring-white/10"></span>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm {{ $exercicio->is_active ? 'text-slate-800 dark:text-slate-200' : 'text-slate-400 line-through dark:text-slate-500' }}">{{ $exercicio->name }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                    {{ $exercicio->measure_type->label() }}@if ($exercicio->equipment_hint) · {{ $exercicio->equipment_hint }}@endif
                                    · @if ($exercicio->hasAnimatedImage()) animado (2 quadros) @elseif ($exercicio->image_path) 1 quadro @else sem foto @endif
                                    @unless ($exercicio->is_active) · desativado @endunless
                                </p>
                            </div>

                            <span class="whitespace-nowrap text-sm">
                                <button type="button" wire:click="editar('{{ $exercicio->id }}')" class="text-slate-500 hover:underline dark:text-slate-400">Editar</button>
                                <button type="button" wire:click="alternarAtivo('{{ $exercicio->id }}')" class="ml-3 text-slate-500 hover:underline dark:text-slate-400">{{ $exercicio->is_active ? 'Desativar' : 'Ativar' }}</button>
                                <button
                                    type="button"
                                    wire:click="excluir('{{ $exercicio->id }}')"
                                    wire:confirm="Excluir {{ $exercicio->name }} do catálogo? As fotos do catálogo também são apagadas. Os treinos que já usam esse exercício não mudam."
                                    class="ml-3 text-red-700 hover:underline dark:text-red-400"
                                >Excluir</button>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="card px-5 py-4 text-sm text-slate-400 dark:text-slate-500">Nenhum exercício encontrado.</p>
        @endforelse
    </div>

    <x-modal wire-model="showForm">
        <form wire:submit="salvar" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editandoId ? 'Editar exercício' : ($sugestaoId ? 'Aprovar sugestão' : 'Novo exercício') }}</h2>
                <button type="button" wire:click="fecharForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="formName" class="input mt-1.5" placeholder="Ex.: Supino reto com barra">
                    @error('formName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Grupo muscular</label>
                    <select wire:model="formGroup" class="input mt-1.5">
                        @foreach (\App\Enums\GymMuscleGroup::cases() as $grupo)
                            <option value="{{ $grupo->value }}">{{ $grupo->label() }}</option>
                        @endforeach
                    </select>
                    @error('formGroup') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo de medida</label>
                    <select wire:model="formType" class="input mt-1.5">
                        @foreach (\App\Enums\GymMeasureType::cases() as $tipo)
                            <option value="{{ $tipo->value }}">{{ $tipo->label() }}</option>
                        @endforeach
                    </select>
                    @error('formType') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Equipamento (opcional)</label>
                    <input type="text" wire:model="formEquipment" class="input mt-1.5" placeholder="Ex.: Barra livre">
                    @error('formEquipment') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @foreach ([1 => ['foto1', 'removerFoto1', 'Quadro 1 (início do movimento)'], 2 => ['foto2', 'removerFoto2', 'Quadro 2 (fim do movimento)']] as $quadro => [$campo, $remover, $rotulo])
                    <div x-data="{ preview: null }" wire:key="quadro-{{ $quadro }}">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">{{ $rotulo }}</label>

                        @if ($this->editando?->imageUrl($quadro) && ! $this->{$remover})
                            <div class="mt-1.5 flex items-center gap-3">
                                <img src="{{ $this->editando->imageUrl($quadro) }}" alt="" class="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-white/10">
                                <button type="button" wire:click="$set('{{ $remover }}', true)" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Remover</button>
                            </div>
                        @else
                            <input type="file" accept="image/*" wire:model="{{ $campo }}"
                                x-on:change="preview = ($event.target.files[0]) ? URL.createObjectURL($event.target.files[0]) : null"
                                class="input mt-1.5">
                            <template x-if="preview">
                                <img :src="preview" alt="" class="mt-2 h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-white/10">
                            </template>
                            <p wire:loading wire:target="{{ $campo }}" class="mt-1 text-xs text-slate-400">Enviando foto...</p>
                        @endif
                        @error($campo) <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações (opcional)</label>
                    <textarea wire:model="formNotes" rows="2" class="input mt-1.5"></textarea>
                    @error('formNotes') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-slate-600 @sm:col-span-2 dark:text-slate-300">
                    <input type="checkbox" wire:model="formActive" class="rounded border-slate-300 dark:border-white/20">
                    Ativo (aparece na lista de quem monta o treino)
                </label>
            </div>

            <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">Salvar</button>
        </form>
    </x-modal>
</div>
