@use('App\Enums\GymMeasureType')
@use('App\Enums\GymMuscleGroup')

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="{{ route('health.gym.index') }}" wire:navigate class="text-xs text-slate-500 hover:underline dark:text-slate-400">← Academia</a>
            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Plano de treino</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $plan ? $plan->name : 'Comece criando a fase do seu treino.' }}
            </p>
        </div>
        <button type="button" wire:click="togglePlanForm" class="btn-secondary">
            {{ $plan ? 'Nova fase' : 'Criar fase' }}
        </button>
    </div>

    <x-modal wire-model="showPlanForm" max-width="sm">
        <form wire:submit="savePlan" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $plan ? 'Nova fase' : 'Criar fase' }}</h2>
                <button type="button" wire:click="togglePlanForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome da fase</label>
                <input type="text" wire:model="planName" class="input mt-1.5" placeholder="Ex.: Fase 1 — Hipertrofia">
                @error('planName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            @if ($plan)
                <p class="text-xs text-slate-400">A fase atual é encerrada e fica no histórico.</p>
            @endif
            <button type="submit" class="btn-primary w-full">Salvar</button>
        </form>
    </x-modal>

    @if ($plan)
        <form wire:submit="addWorkout" class="card grid gap-3 p-5 sm:grid-cols-[1fr_2fr_auto] sm:items-end">
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Novo treino</label>
                <input type="text" wire:model="workoutName" class="input mt-1.5" placeholder="Treino A">
                @error('workoutName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Foco (opcional)</label>
                <input type="text" wire:model="workoutFocus" class="input mt-1.5" placeholder="Peito, ombros e core">
                @error('workoutFocus') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn-primary">Adicionar</button>
        </form>

        @error('workout') <p class="text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

        @if ($swapNotice)
            <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">{{ $swapNotice }}</p>
        @endif

        @foreach ($workouts as $treino)
            <section class="card p-5" wire:key="w-{{ $treino->id }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-slate-900 sm:text-sm dark:text-white">{{ $treino->name }}</h2>
                        @if ($treino->focus)
                            <p class="text-sm text-slate-500 sm:text-xs dark:text-slate-400">{{ $treino->focus }}</p>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 sm:gap-1">
                        <button type="button" wire:click="moveWorkout('{{ $treino->id }}', -1)" @disabled($loop->first) class="btn-ghost w-10 border border-slate-200 px-0 py-2 text-sm disabled:opacity-30 sm:w-auto sm:px-2 sm:border-0 sm:px-2 sm:py-1 sm:text-xs dark:border-white/10" aria-label="Subir treino">↑</button>
                        <button type="button" wire:click="moveWorkout('{{ $treino->id }}', 1)" @disabled($loop->last) class="btn-ghost w-10 border border-slate-200 px-0 py-2 text-sm disabled:opacity-30 sm:w-auto sm:px-2 sm:border-0 sm:px-2 sm:py-1 sm:text-xs dark:border-white/10" aria-label="Descer treino">↓</button>
                        <button type="button" wire:click="deleteWorkout('{{ $treino->id }}')" wire:confirm="Excluir {{ $treino->name }} e seus exercícios?" class="btn-ghost flex-1 border border-red-200 px-2 py-2 text-sm text-red-700 sm:flex-none sm:border-0 sm:px-2 sm:py-1 sm:text-xs dark:border-red-500/20 dark:text-red-400">Excluir treino</button>
                    </div>
                </div>

                @if ($treino->workoutExercises->isEmpty())
                    <p class="mt-4 text-xs text-slate-400">Nenhum exercício ainda.</p>
                @else
                    <ul class="mt-4 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($treino->workoutExercises as $item)
                            <li class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between sm:py-3" wire:key="i-{{ $item->id }}">
                                <div class="flex min-w-0 items-start gap-3">
                                    <x-gym.exercise-photo :exercise="$item->exercise" size="h-16 w-16 shrink-0 rounded-xl sm:h-12 sm:w-12 sm:rounded-lg" />
                                    <div class="min-w-0">
                                        <p class="text-base font-medium leading-snug text-slate-900 sm:text-sm sm:font-normal dark:text-white sm:dark:text-slate-200">{{ $item->exercise->name }}</p>
                                        <p class="mt-1 text-sm text-slate-600 sm:mt-0.5 sm:text-xs dark:text-slate-300 sm:dark:text-slate-400">
                                            {{ $item->exercise->muscle_group->label() }}
                                            · {{ $item->target_sets }}×
                                            @if ($item->target_duration_seconds)
                                                {{ $item->target_duration_seconds }}s
                                            @elseif ($item->target_reps_min && $item->target_reps_max && $item->target_reps_min !== $item->target_reps_max)
                                                {{ $item->target_reps_min }}–{{ $item->target_reps_max }}
                                            @elseif ($item->target_reps_min || $item->target_reps_max)
                                                {{ $item->target_reps_min ?? $item->target_reps_max }}
                                            @else
                                                —
                                            @endif
                                            @if ($item->rest_seconds !== null) · pausa {{ $item->rest_seconds }}s @endif
                                            @if ($item->defaultEquipment) · {{ $item->defaultEquipment->name }} @endif
                                        </p>
                                        @if ($item->notes)
                                            <p class="mt-1 text-sm leading-snug text-slate-500 sm:mt-0.5 sm:text-xs sm:text-slate-400 dark:text-slate-400">{{ $item->notes }}</p>
                                        @endif
                                    </div>
                                </div>
                                {{-- No celular os botões descem para uma linha própria, com área de toque maior; a partir de sm ficam ao lado. --}}
                                <div class="flex shrink-0 items-center gap-1.5 sm:gap-1">
                                    <button type="button" wire:click="moveExercise('{{ $item->id }}', -1)" @disabled($loop->first) class="btn-ghost w-10 border border-slate-200 px-0 py-2 text-sm disabled:opacity-30 sm:w-auto sm:px-2 sm:border-0 sm:px-2 sm:py-1 sm:text-xs dark:border-white/10" aria-label="Subir exercício">↑</button>
                                    <button type="button" wire:click="moveExercise('{{ $item->id }}', 1)" @disabled($loop->last) class="btn-ghost w-10 border border-slate-200 px-0 py-2 text-sm disabled:opacity-30 sm:w-auto sm:px-2 sm:border-0 sm:px-2 sm:py-1 sm:text-xs dark:border-white/10" aria-label="Descer exercício">↓</button>
                                    <button type="button" wire:click="openSwap('{{ $item->id }}')" class="btn-ghost flex-1 border border-slate-200 px-2 py-2 text-sm sm:flex-none sm:border-0 sm:px-2 sm:py-1 sm:text-xs dark:border-white/10">Trocar</button>
                                    <button type="button" wire:click="editExercise('{{ $item->id }}')" class="btn-ghost flex-1 border border-slate-200 px-2 py-2 text-sm sm:flex-none sm:border-0 sm:px-2 sm:py-1 sm:text-xs dark:border-white/10">Editar</button>
                                    <button type="button" wire:click="removeExercise('{{ $item->id }}')" wire:confirm="Remover {{ $item->exercise->name }} deste treino?" class="btn-ghost flex-1 border border-red-200 px-2 py-2 text-sm text-red-700 sm:flex-none sm:border-0 sm:px-2 sm:py-1 sm:text-xs dark:border-red-500/20 dark:text-red-400">Remover</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <button type="button" wire:click="newExercise('{{ $treino->id }}')" class="btn-secondary mt-3 text-xs">+ Exercício</button>
            </section>
        @endforeach
    @endif

    {{-- Trocar exercício: a troca fica no treino, para as próximas vezes. --}}
    <x-modal wire-model="showSwap" max-width="lg">
        @if ($swapItem)
            <div class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Trocar exercício</h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            Saindo: {{ $swapItem->exercise->name }} · {{ $swapItem->exercise->muscle_group->label() }}. Séries, repetições e pausa continuam.
                        </p>
                    </div>
                    <button type="button" wire:click="closeSwap" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                @error('swap') <p class="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p> @enderror

                <div class="flex flex-wrap items-center gap-3">
                    <input type="search" wire:model.live.debounce.250ms="swapSearch" class="input min-w-0 flex-1" placeholder="Buscar por nome ou equipamento">
                    <label class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        <input type="checkbox" wire:model.live="swapAllGroups" class="h-4 w-4 rounded border-slate-300">
                        Todos os grupos
                    </label>
                </div>

                <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-white/10 dark:border-white/10">
                    @forelse ($swapOptions as $opcao)
                        <button type="button" wire:click="swapTo('{{ $opcao['key'] }}')" wire:key="troca-{{ $opcao['key'] }}"
                            class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left hover:bg-slate-50 dark:hover:bg-white/5">
                            <span class="min-w-0">
                                <span class="block truncate text-sm text-slate-800 dark:text-slate-200">{{ $opcao['name'] }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">
                                    {{ $opcao['group'] }}@if ($opcao['equipment']) · {{ $opcao['equipment'] }}@endif
                                </span>
                            </span>
                            <span class="shrink-0 text-xs {{ $opcao['own'] ? 'text-saude-800 dark:text-saude-200' : 'text-slate-400' }}">{{ $opcao['own'] ? 'Você já usa' : 'Catálogo' }}</span>
                        </button>
                    @empty
                        <p class="px-3 py-6 text-center text-xs text-slate-400">Nenhum exercício bate com a busca.</p>
                    @endforelse
                </div>

                <form wire:submit="swapToCustom" class="flex flex-wrap items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Não achou? Digite o nome</label>
                        <input type="text" wire:model="swapCustomName" class="input mt-1.5" placeholder="Ex.: Supino na máquina Smith">
                        @error('swapCustomName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn-secondary">Usar este</button>
                </form>
            </div>
        @endif
    </x-modal>

    <x-modal wire-model="showExerciseForm">
        <form wire:submit="saveExercise" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExisting ? 'Editar exercício' : 'Novo exercício' }}</h2>
                <button type="button" wire:click="closeExerciseForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Exercício</label>
                    <input type="text" wire:model.live="exName" list="gym-exercise-names" @disabled($editingExisting) class="input mt-1.5 disabled:opacity-60" placeholder="Ex.: Supino reto com halteres">
                    <datalist id="gym-exercise-names">
                        @foreach ($exerciseNames as $nome) <option value="{{ $nome }}"></option> @endforeach
                    </datalist>
                    @if ($catalogHint)
                        <p class="mt-1 text-xs text-saude-800 dark:text-saude-200">{{ $catalogHint }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-400">Digite um nome do catálogo para preencher grupo, tipo e foto sozinho.</p>
                    @endif
                    @error('exName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Grupo muscular</label>
                    <select wire:model="exGroup" @disabled($editingExisting) class="select mt-1.5 w-full disabled:opacity-60">
                        @foreach (GymMuscleGroup::options() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Medido por</label>
                    <select wire:model.live="exType" @disabled($editingExisting) class="select mt-1.5 w-full disabled:opacity-60">
                        @foreach (GymMeasureType::options() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Séries</label>
                    <input type="number" min="1" max="20" wire:model="exSets" class="input mt-1.5">
                    @error('exSets') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @if ($timed)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Duração-alvo (segundos)</label>
                        <input type="number" min="5" wire:model="exDuration" class="input mt-1.5" placeholder="Ex.: 60">
                        @error('exDuration') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pausa entre séries (segundos)</label>
                        <input type="number" min="0" max="600" wire:model="exRest" class="input mt-1.5" placeholder="Opcional">
                        @error('exRest') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <p class="text-xs text-slate-400 @sm:col-span-2">Exercício em tempo usa cronômetro (iniciar → concluir). Cada série é uma rodada do cronômetro: em um intervalado de 8 esforços de 30 s com 60 s leve, use 8 séries, 30 s e pausa 60. Sem pausa, deixe em branco.</p>
                @else
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Repetições (mín.)</label>
                        <input type="number" min="1" wire:model="exRepsMin" class="input mt-1.5" placeholder="8">
                        @error('exRepsMin') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Repetições (máx.)</label>
                        <input type="number" min="1" wire:model="exRepsMax" class="input mt-1.5" placeholder="12">
                        @error('exRepsMax') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pausa (segundos)</label>
                        <input type="number" min="0" max="600" wire:model="exRest" class="input mt-1.5" placeholder="60">
                        @error('exRest') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Equipamento habitual (opcional)</label>
                    <input type="text" wire:model="exEquipment" list="gym-equipment-names" class="input mt-1.5" placeholder="Ex.: Halteres, Smith, Polia">
                    <datalist id="gym-equipment-names">
                        @foreach ($equipmentNames as $nome) <option value="{{ $nome }}"></option> @endforeach
                    </datalist>
                    @error('exEquipment') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2" x-data="{ preview: null }">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Foto do exercício (opcional)</label>

                    @if ($exImageUrl && ! $removeImage)
                        <div class="mt-1.5 flex items-center gap-3">
                            <img src="{{ $exImageUrl }}" alt="" class="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-white/10">
                            <button type="button" wire:click="$set('removeImage', true)" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Remover foto</button>
                        </div>
                    @else
                        <input type="file" accept="image/*" wire:model="exImage"
                            x-on:change="preview = ($event.target.files[0]) ? URL.createObjectURL($event.target.files[0]) : null"
                            class="input mt-1.5">
                        <template x-if="preview">
                            <img :src="preview" alt="" class="mt-2 h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-white/10">
                        </template>
                        <p wire:loading wire:target="exImage" class="mt-1 text-xs text-slate-400">Enviando foto...</p>
                    @endif
                    @error('exImage') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações (opcional)</label>
                    <textarea wire:model="exNotes" rows="2" class="input mt-1.5"></textarea>
                    @error('exNotes') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit" class="btn-primary w-full">Salvar</button>
        </form>
    </x-modal>
</div>
