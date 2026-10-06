<div class="space-y-8">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Seguradoras</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $aprovadas->count() }} {{ $aprovadas->count() === 1 ? 'seguradora aprovada' : 'seguradoras aprovadas' }} · {{ $this->sugestoes->count() }} {{ $this->sugestoes->count() === 1 ? 'sugestão pendente' : 'sugestões pendentes' }}
        </p>
    </div>

    <div class="space-y-2">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Sugestões pendentes</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Nomes que algum cliente digitou no cadastro de apólice e que não batem com nenhuma seguradora aprovada.
            Aprovar deixa visível para todos; dispensar só tira da fila. Quem sugeriu continua usando normalmente.
        </p>

        <div class="card overflow-hidden p-0">
            @if ($this->sugestoes->isEmpty())
                <p class="px-5 py-4 text-sm text-slate-400 dark:text-slate-500">Nenhuma sugestão pendente no momento.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-2 font-medium">Nome sugerido</th>
                            <th class="px-5 py-2 font-medium">Sugerido por</th>
                            <th class="px-5 py-2 font-medium">Cor</th>
                            <th class="px-5 py-2 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($this->sugestoes as $seguradora)
                            <tr wire:key="sugestao-{{ $seguradora->id }}">
                                <td class="px-5 py-2.5 text-slate-800 dark:text-slate-200">{{ $seguradora->name }}</td>
                                <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">
                                    @if ($seguradora->profile)
                                        {{ $seguradora->profile->profile_name }} <span class="text-xs text-slate-400 dark:text-slate-500">({{ $seguradora->profile->owner->email }})</span>
                                    @else
                                        <span class="text-slate-400 dark:text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5">
                                    <input type="color" wire:model="corAprovacao.{{ $seguradora->id }}" class="h-8 w-12 cursor-pointer rounded border-0 bg-transparent p-0">
                                </td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                    <button type="button" wire:click="aprovar('{{ $seguradora->id }}')" class="text-sm font-medium text-accent-700 hover:underline dark:text-accent-400">Aprovar</button>
                                    <button type="button" wire:click="dispensar('{{ $seguradora->id }}')" class="ml-3 text-sm text-slate-500 hover:underline dark:text-slate-400">Dispensar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="space-y-2">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Adicionar seguradora</h2>
        <form wire:submit="adicionar" class="card flex flex-wrap items-end gap-3 p-5">
            <div class="min-w-48 flex-1">
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                <input type="text" wire:model="novoNome" class="input mt-1.5" placeholder="Ex.: Porto Seguro">
                @error('novoNome') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Cor</label>
                <input type="color" wire:model="novaCor" class="mt-1.5 h-10 w-14 cursor-pointer rounded border-0 bg-transparent p-0">
            </div>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Adicionar</button>
        </form>
    </div>

    <div class="space-y-2">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Seguradoras aprovadas</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Renomear, trocar a cor ou remover não muda as apólices já cadastradas: elas guardam o nome que o cliente digitou.
            A cor aparece no selo da seguradora nas telas de seguros.
        </p>

        <div class="card divide-y divide-slate-100 p-0 dark:divide-white/10">
            @foreach ($aprovadas as $seguradora)
                <div class="px-5 py-2.5" wire:key="aprovada-{{ $seguradora->id }}">
                    @if ($editandoId === $seguradora->id)
                        <form wire:submit="salvarEdicao" class="flex flex-wrap items-end gap-3">
                            <div class="min-w-48 flex-1">
                                <input type="text" wire:model="editNome" class="input" aria-label="Nome da seguradora">
                                @error('editNome') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <input type="color" wire:model="editCor" class="h-10 w-14 cursor-pointer rounded border-0 bg-transparent p-0" aria-label="Cor">
                            <button type="submit" class="btn-primary px-3 py-1.5 text-xs" wire:loading.attr="disabled">Salvar</button>
                            <button type="button" wire:click="cancelarEdicao" class="btn-ghost px-3 py-1.5 text-xs">Cancelar</button>
                        </form>
                    @else
                        <div class="flex items-center justify-between gap-3">
                            <span class="inline-flex items-center gap-2.5 text-sm text-slate-800 dark:text-slate-200">
                                <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $seguradora->color_hex }}"></span>
                                {{ $seguradora->name }}
                            </span>
                            <span class="whitespace-nowrap">
                                <button type="button" wire:click="iniciarEdicao('{{ $seguradora->id }}')" class="text-sm text-slate-500 hover:underline dark:text-slate-400">Editar</button>
                                <button
                                    type="button"
                                    wire:click="remover('{{ $seguradora->id }}')"
                                    wire:confirm="Remover {{ $seguradora->name }} da lista? As apólices que já usam esse nome não mudam."
                                    class="ml-3 text-sm text-red-700 hover:underline dark:text-red-400"
                                >Remover</button>
                            </span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
