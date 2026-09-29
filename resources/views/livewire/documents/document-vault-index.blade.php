@use('App\Enums\DocumentCategory')

<div class="space-y-6">

    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Documentos</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                CNH, passaporte, certificados, apólices e exames — guardados num lugar só.
            </p>
        </div>
        @if ($podeGerenciar)
            <button type="button" wire:click="newDocument" class="btn-primary shrink-0 px-3 py-2 text-sm">+ Novo documento</button>
        @endif
    </div>

    <section class="card space-y-3 p-5">
        @if ($documents->isEmpty())
            <p class="text-xs text-slate-400">Nenhum documento guardado ainda.</p>
        @else
            <ul class="space-y-2">
                @foreach ($documents as $documento)
                    <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 p-3 dark:border-white/10" wire:key="doc-{{ $documento->id }}">
                        <a href="{{ route('documents.vault.file', $documento->id) }}" target="_blank" rel="noopener" class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $documento->title }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $documento->category->label() }}
                                @if ($documento->member)
                                    · {{ $documento->member->name }}
                                @else
                                    · Documento da família
                                @endif
                                @if ($documento->category === DocumentCategory::InsurancePolicy && $documento->insurancePolicy)
                                    · {{ $documento->insurancePolicy->insurer_name }}
                                @endif
                                @if ($documento->expires_on)
                                    · <span class="{{ $documento->expires_on->isPast() ? 'text-red-700 dark:text-red-400' : '' }}">vence {{ $documento->expires_on->format('d/m/Y') }}</span>
                                @endif
                            </p>
                        </a>
                        @if ($podeGerenciar)
                            <div class="flex shrink-0 items-center gap-2">
                                <button type="button" wire:click="editDocument('{{ $documento->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                                <button type="button" wire:click="delete('{{ $documento->id }}')" wire:confirm="Remover este documento?" class="text-xs text-slate-400 hover:text-red-700 dark:hover:text-red-400">
                                    remover
                                </button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($podeGerenciar)
        <x-modal wire-model="showForm">
            <form wire:submit="save" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExisting ? 'Editar documento' : 'Novo documento' }}</h2>
                    <button type="button" wire:click="closeForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
                        <select wire:model.live="category" class="select mt-1.5">
                            <option value=""></option>
                            @foreach (DocumentCategory::options() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('category') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Título</label>
                        <input type="text" wire:model="title" class="input mt-1.5" placeholder="Ex.: CNH — Marcelo" maxlength="120">
                        @error('title') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa (opcional)</label>
                        <select wire:model="memberId" class="select mt-1.5">
                            <option value="">Documento da família</option>
                            @foreach ($membros as $membro)
                                <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($category === DocumentCategory::InsurancePolicy->value)
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Apólice</label>
                            <select wire:model="insurancePolicyId" class="select mt-1.5">
                                <option value=""></option>
                                @foreach ($policies as $apolice)
                                    <option value="{{ $apolice->id }}">{{ $apolice->insurer_name }} — {{ $apolice->personLabel() ?? 'seguro familiar' }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-400">Visível ao consultor/corretor exatamente como a apólice já é hoje.</p>
                            @error('insurancePolicyId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data de validade (opcional)</label>
                        <input type="date" wire:model="expiresOn" class="input mt-1.5">
                        @error('expiresOn') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @if ($category === DocumentCategory::Other->value)
                        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                            <input type="checkbox" wire:model="visibleToProfessional" class="h-4 w-4 rounded accent-documentos-800">
                            Visível ao consultor/corretor vinculado
                        </label>
                    @endif

                    @if (! $editingExisting)
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Arquivo (PDF, JPG ou PNG)</label>
                            <input type="file" wire:model="arquivo" accept=".pdf,.jpg,.jpeg,.png" class="input mt-1.5">
                            <div wire:loading wire:target="arquivo" class="mt-1 text-xs text-slate-400">Enviando...</div>
                            @error('arquivo') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                <button type="submit" class="btn-primary w-full">Salvar</button>
            </form>
        </x-modal>
    @endif
</div>
