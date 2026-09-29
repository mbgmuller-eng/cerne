@use('App\Enums\HealthMedicationChangeType')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Ficha de Saúde</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Tipo sanguíneo, alergias, doenças e remédios — visível pra você e seu cônjuge. Consultor e corretor não têm acesso.
        </p>
    </div>

    @foreach ($fichas as $ficha)
        @php [$membro, $card, $allergies, $conditions, $medications] = [$ficha['membro'], $ficha['card'], $ficha['allergies'], $ficha['conditions'], $ficha['medications']]; @endphp

        <section class="card space-y-5 p-5" wire:key="ficha-{{ $membro->id }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-display text-xl font-semibold text-slate-900 dark:text-white">{{ $membro->name }}</h2>
                <div class="flex items-center gap-2">
                    <label class="text-xs text-slate-500 dark:text-slate-400">Tipo sanguíneo</label>
                    <select wire:model.live="bloodTypeDrafts.{{ $membro->id }}" class="select w-24">
                        <option value=""></option>
                        @foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $tipo)
                            <option value="{{ $tipo }}">{{ $tipo }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="grid gap-5 @lg:grid-cols-2">
                {{-- Alergias --}}
                <div>
                    <p class="eyebrow">Alergias</p>
                    @if ($allergies->isEmpty())
                        <p class="mt-2 text-xs text-slate-400">Nenhuma registrada.</p>
                    @else
                        <ul class="mt-2 space-y-1">
                            @foreach ($allergies as $alergia)
                                <li class="flex items-center justify-between gap-2 text-sm" wire:key="al-{{ $alergia->id }}">
                                    <span class="text-slate-700 dark:text-slate-300">{{ $alergia->description }}</span>
                                    <button type="button" wire:click="removeAllergy('{{ $alergia->id }}')" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">remover</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="addAllergy('{{ $membro->id }}')" class="mt-2 flex gap-2">
                        <input type="text" wire:model="newAllergyText.{{ $membro->id }}" class="input" placeholder="Ex.: Dipirona" maxlength="160">
                        <button type="submit" class="btn-secondary shrink-0 px-3 text-xs">Adicionar</button>
                    </form>
                </div>

                {{-- Doenças / comorbidades --}}
                <div>
                    <p class="eyebrow">Doenças e comorbidades</p>
                    @if ($conditions->isEmpty())
                        <p class="mt-2 text-xs text-slate-400">Nenhuma registrada.</p>
                    @else
                        <ul class="mt-2 space-y-1">
                            @foreach ($conditions as $condicao)
                                <li class="flex items-center justify-between gap-2 text-sm" wire:key="co-{{ $condicao->id }}">
                                    <span class="text-slate-700 dark:text-slate-300">{{ $condicao->description }}</span>
                                    <button type="button" wire:click="removeCondition('{{ $condicao->id }}')" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">remover</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="addCondition('{{ $membro->id }}')" class="mt-2 flex gap-2">
                        <input type="text" wire:model="newConditionText.{{ $membro->id }}" class="input" placeholder="Ex.: Hipertensão" maxlength="160">
                        <button type="submit" class="btn-secondary shrink-0 px-3 text-xs">Adicionar</button>
                    </form>
                </div>
            </div>

            {{-- Remédios --}}
            <div>
                <div class="flex items-center justify-between">
                    <p class="eyebrow">Remédios</p>
                    <button type="button" wire:click="newMedication('{{ $membro->id }}')" class="btn-ghost px-2 py-1 text-xs">+ Remédio</button>
                </div>

                @if ($medications->isEmpty())
                    <p class="mt-2 text-xs text-slate-400">Nenhum registrado.</p>
                @else
                    <ul class="mt-2 space-y-3">
                        @foreach ($medications as $remedio)
                            <li class="rounded-xl border border-slate-100 p-3 dark:border-white/10 {{ ! $remedio->is_active ? 'opacity-60' : '' }}" wire:key="med-{{ $remedio->id }}">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                            {{ $remedio->name }}
                                            @if (! $remedio->is_active)
                                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-white/10 dark:text-slate-400">suspenso</span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                            {{ collect([$remedio->dose, $remedio->schedule, $remedio->reason])->filter()->implode(' · ') ?: '—' }}
                                        </p>
                                        @if ($remedio->prescriber)
                                            <p class="text-xs text-slate-400">Prescrito por {{ $remedio->prescriber }}</p>
                                        @endif
                                    </div>
                                    <button type="button" wire:click="editMedication('{{ $remedio->id }}')" class="btn-ghost shrink-0 px-2 py-1 text-xs">Editar</button>
                                </div>

                                @if ($remedio->changes->isNotEmpty())
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs text-saude-800 dark:text-saude-200">Histórico ({{ $remedio->changes->count() }})</summary>
                                        <ul class="mt-2 space-y-1.5 border-l border-slate-200 pl-3 dark:border-white/10">
                                            @foreach ($remedio->changes as $mudanca)
                                                <li class="text-xs text-slate-500 dark:text-slate-400">
                                                    <span class="text-slate-700 dark:text-slate-300">{{ $mudanca->created_at->format('d/m/Y') }} — {{ $mudanca->change_type->label() }}</span>
                                                    @if ($mudanca->change_type === HealthMedicationChangeType::DoseChanged)
                                                        <span>({{ $mudanca->old_value ?: '—' }} → {{ $mudanca->new_value ?: '—' }})</span>
                                                    @endif
                                                    @if ($mudanca->note)
                                                        <span>— {{ $mudanca->note }}</span>
                                                    @endif
                                                    @if ($mudanca->changedBy)
                                                        <span class="text-slate-400">· por {{ $mudanca->changedBy->name }}</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif

                                <form wire:submit="addMedicationNote('{{ $remedio->id }}')" class="mt-2 flex gap-2">
                                    <input type="text" wire:model="medNoteText.{{ $remedio->id }}" class="input text-xs" placeholder="Adicionar observação ao histórico..." maxlength="500">
                                    <button type="submit" class="btn-ghost shrink-0 px-2 text-xs">Salvar</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endforeach

    <x-modal wire-model="showMedicationForm">
        <form wire:submit="saveMedication" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExisting ? 'Editar remédio' : 'Novo remédio' }}</h2>
                <button type="button" wire:click="closeMedicationForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2">
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="medName" class="input mt-1.5" placeholder="Ex.: Losartana">
                    @error('medName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dose</label>
                    <input type="text" wire:model="medDose" class="input mt-1.5" placeholder="Ex.: 50mg">
                    @error('medDose') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Horário</label>
                    <input type="text" wire:model="medSchedule" class="input mt-1.5" placeholder="Ex.: 1x ao dia, manhã">
                    @error('medSchedule') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Motivo (opcional)</label>
                    <input type="text" wire:model="medReason" class="input mt-1.5" placeholder="Ex.: Pressão alta">
                    @error('medReason') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Médico que prescreveu (opcional)</label>
                    <input type="text" wire:model="medPrescriber" class="input mt-1.5" placeholder="Ex.: Dr. Souza — cardiologista">
                    @error('medPrescriber') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @if (! $editingExisting)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Início (opcional)</label>
                        <input type="date" wire:model="medStartedOn" class="input mt-1.5">
                        @error('medStartedOn') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @else
                    <label class="mt-5 flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" wire:model="medActive" class="h-4 w-4 rounded accent-saude-800">
                        Em uso (desmarcar suspende e registra no histórico)
                    </label>
                @endif
            </div>

            <button type="submit" class="btn-primary w-full">Salvar</button>
        </form>
    </x-modal>
</div>
