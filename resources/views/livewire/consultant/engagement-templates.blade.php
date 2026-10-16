<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Modelos de roteiro</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">A sua metodologia em etapas, para aplicar ao passo a passo de cada cliente. Só você vê estes modelos.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="copyDefault" class="btn-ghost px-3 py-1.5 text-sm">Copiar o roteiro padrão</button>
            <button type="button" wire:click="openForm" class="btn-secondary px-3 py-1.5 text-sm">Novo modelo</button>
        </div>
    </div>

    @if ($modelos->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">Você ainda não tem modelos próprios.</p>
            <p class="mt-1 text-xs text-slate-400">Comece copiando o roteiro padrão do Cerne e ajuste as etapas ao seu jeito de trabalhar.</p>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($modelos as $linha)
                <li class="card flex flex-wrap items-center justify-between gap-3 p-4" wire:key="modelo-{{ $linha['model']->id }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $linha['model']->name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $linha['stages'] }} {{ $linha['stages'] === 1 ? 'etapa' : 'etapas' }}</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="openForm('{{ $linha['model']->id }}')" class="btn-secondary px-3 py-1.5 text-xs">Editar</button>
                        <button type="button" wire:click="delete('{{ $linha['model']->id }}')" wire:confirm="Apagar este modelo? Os roteiros dos clientes que já o usam não mudam." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Apagar</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <x-modal wire-model="showForm" max-width="2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingId ? 'Editar modelo' : 'Novo modelo' }}</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do modelo</label>
                <input type="text" wire:model="name" maxlength="120" class="input mt-1.5">
                @error('name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Etapas e itens</label>
                <textarea wire:model="body" rows="14" class="input mt-1.5 font-mono text-xs"></textarea>
                @error('body') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <details class="text-xs text-slate-500 dark:text-slate-400">
                <summary class="cursor-pointer">Como escrever</summary>
                <div class="mt-2 space-y-2">
                    <p>Uma linha com <code># </code> abre uma etapa; uma linha com <code>- </code> é um item dela. No fim do item, <code>&#64;critério</code> o marca sozinho quando o cliente cumpre aquilo no Cerne. Sem critério, só você marca.</p>
                    <ul class="space-y-0.5">
                        @foreach ($criterios as $chave => $rotulo)
                            <li><code>&#64;{{ $chave }}</code> · {{ $rotulo }}</li>
                        @endforeach
                    </ul>
                </div>
            </details>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar modelo</button></div>
        </form>
    </x-modal>
</div>
