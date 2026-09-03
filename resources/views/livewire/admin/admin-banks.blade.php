<div class="space-y-8">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Bancos</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $aprovados->count() }} {{ $aprovados->count() === 1 ? 'banco aprovado' : 'bancos aprovados' }} · {{ $this->sugestoes->count() }} {{ $this->sugestoes->count() === 1 ? 'sugestão pendente' : 'sugestões pendentes' }}
        </p>
    </div>

    <div class="space-y-2">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Sugestões pendentes</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Nomes que algum cliente digitou no cadastro de conta ou cartão e que não batem com nenhum banco aprovado.
            Aprovar deixa visível pra todo mundo; dispensar só tira da fila — quem sugeriu continua usando normalmente.
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
                        @foreach ($this->sugestoes as $banco)
                            <tr>
                                <td class="px-5 py-2.5 text-slate-800 dark:text-slate-200">{{ $banco->name }}</td>
                                <td class="px-5 py-2.5 text-slate-500 dark:text-slate-400">
                                    @if ($banco->profile)
                                        {{ $banco->profile->profile_name }} <span class="text-xs text-slate-400 dark:text-slate-500">({{ $banco->profile->owner->email }})</span>
                                    @else
                                        <span class="text-slate-400 dark:text-slate-500">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2.5">
                                    <input type="color" wire:model="corAprovacao.{{ $banco->id }}" class="h-8 w-12 cursor-pointer rounded border-0 bg-transparent p-0">
                                </td>
                                <td class="px-5 py-2.5 text-right whitespace-nowrap">
                                    <button type="button" wire:click="aprovar('{{ $banco->id }}')" class="text-sm font-medium text-accent-700 hover:underline dark:text-accent-400">Aprovar</button>
                                    <button type="button" wire:click="dispensar('{{ $banco->id }}')" class="ml-3 text-sm text-slate-500 hover:underline dark:text-slate-400">Dispensar</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    <div class="space-y-2">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Bancos aprovados</h2>

        <div class="card flex flex-wrap gap-2 p-5">
            @foreach ($aprovados as $banco)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-50 px-3 py-1 text-xs text-slate-700 ring-1 ring-slate-200 dark:bg-slate-700 dark:text-slate-200 dark:ring-slate-600">
                    <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ $banco->color_hex }}"></span>
                    {{ $banco->name }}
                </span>
            @endforeach
        </div>
    </div>
</div>
