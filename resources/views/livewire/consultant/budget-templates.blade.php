@php
    $resumo = function (array $s): string {
        $a = $s['areas'] ?? [];
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');

        return $fmt($a['essential'] ?? 0).' / '.$fmt($a['discretionary'] ?? 0).' / '.$fmt($a['investment'] ?? 0);
    };
@endphp

<div class="space-y-6" data-budget-templates>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Modelos de regra</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">A sua forma de dividir a receita, guardada uma vez e aplicada aos clientes que você escolher.</p>
        </div>
        <button type="button" wire:click="newTemplate" class="btn-primary px-4 py-2 text-sm" data-new-template>Novo modelo</button>
    </div>

    @if (session('budget_templates_status'))
        <p class="rounded-xl bg-brand-50 px-3 py-2 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20" data-templates-status>{{ session('budget_templates_status') }}</p>
    @endif

    @if ($modelos->isEmpty())
        <div class="card p-6 text-sm text-slate-500 dark:text-slate-400" data-templates-empty>
            Você ainda não tem modelos. Crie o seu a partir de um modelo pronto, ajuste os percentuais e use "Aplicar a clientes". O cliente continua podendo editar a própria regra depois.
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($modelos as $modelo)
                <li class="card p-5" data-template="{{ $modelo->id }}" wire:key="modelo-{{ $modelo->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $modelo->name }}</p>
                            @if ($modelo->description)
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $modelo->description }}</p>
                            @endif
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Essencial / supérfluo / investimentos: {{ $resumo((array) $modelo->structure) }}</p>
                            <p class="mt-0.5 text-xs text-slate-400">Investimentos em {{ collect($modelo->structure['groups'] ?? [])->pluck('name')->implode(', ') ?: 'nenhum grupo' }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-1">
                            <button type="button" wire:click="openApply('{{ $modelo->id }}')" class="btn-secondary px-3 py-1.5 text-xs" data-apply-template>Aplicar a clientes</button>
                            <button type="button" wire:click="edit('{{ $modelo->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                            <button type="button" wire:click="delete('{{ $modelo->id }}')" wire:confirm="Excluir este modelo? As regras já aplicadas a clientes continuam como estão." class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Excluir</button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Editor do modelo --}}
    <x-modal wire-model="showEditor" max-width="3xl">
        @if ($showEditor)
            <div class="space-y-4">
                <div class="grid gap-3 @sm:grid-cols-2 *:min-w-0">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do modelo</label>
                        <input type="text" wire:model="name" maxlength="120" class="input mt-1.5" placeholder="Ex.: 50/20/30 com tercos" data-template-name>
                        @error('name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Descrição <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="description" maxlength="500" class="input mt-1.5" placeholder="Para quem é este modelo">
                        @error('description') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                @include('livewire.budget.partials.editor', ['presets' => $presets, 'modelos' => collect(), 'saveAction' => 'saveTemplate', 'showStart' => false, 'previa' => null, 'editorTitle' => $editingId ? 'Editar modelo' : 'Novo modelo'])
            </div>
        @endif
    </x-modal>

    {{-- Aplicar a clientes --}}
    <x-modal wire-model="showApply" max-width="lg">
        @if ($showApply)
            <div class="space-y-4" data-apply-modal>
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Aplicar a clientes</h2>
                    <button type="button" wire:click="closeApply" class="btn-ghost px-2 py-1 text-xs">Fechar</button>
                </div>

                <div class="max-w-xs">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vale a partir de</label>
                    <input type="month" wire:model="applyYm" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">É uma cópia: mudar o modelo depois não altera a regra do cliente.</p>
                    @error('applyYm') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Clientes</p>
                    @if ($clientes->isEmpty())
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Você não tem clientes com vínculo ativo.</p>
                    @else
                        <ul class="mt-1.5 max-h-64 space-y-0.5 overflow-y-auto rounded-lg border border-slate-200 p-1.5 dark:border-white/10">
                            @foreach ($clientes as $cliente)
                                <li wire:key="cli-{{ $cliente['id'] }}">
                                    <label class="flex cursor-pointer items-center justify-between gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-slate-50 dark:hover:bg-white/5">
                                        <span class="flex min-w-0 items-center gap-2">
                                            <input type="checkbox" wire:model="applyClients" value="{{ $cliente['id'] }}" class="h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                            <span class="truncate text-slate-700 dark:text-slate-300">{{ $cliente['name'] }}</span>
                                        </span>
                                        <span class="shrink-0 text-xs text-slate-400">
                                            {{ $cliente['rule'] ? 'já tem regra desde '.sprintf('%02d/%04d', $cliente['rule']->effective_month, $cliente['rule']->effective_year) : 'sem regra' }}
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @error('applyClients') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-slate-400">Quem já tem regra com início neste mesmo mês é atualizado; as versões de outros meses ficam como estão.</p>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeApply" class="btn-ghost px-3 py-2 text-sm">Cancelar</button>
                    <button type="button" wire:click="applyToClients" wire:loading.attr="disabled" class="btn-primary px-4 py-2 text-sm" data-apply-submit>Aplicar</button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
