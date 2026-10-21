{{-- Ao vivo: a tela se atualiza sozinha. --}}
<div class="space-y-6" wire:poll.30s>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Erros</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Tudo que deu errado na plataforma, agrupado. Atualiza sozinho. O relatório do dia anterior chega por e-mail às 08:10.
            </p>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-5">
            <p class="eyebrow">Abertos</p>
            <p class="figure mt-2 text-3xl font-medium {{ $resumo['open'] > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-slate-900 dark:text-white' }}">{{ $resumo['open'] }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">tipos de erro sem resolver</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Ocorrências hoje</p>
            <p class="figure mt-2 text-3xl font-medium text-slate-900 dark:text-white">{{ $resumo['today'] }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">somando todos os tipos</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Novos hoje</p>
            <p class="figure mt-2 text-3xl font-medium text-slate-900 dark:text-white">{{ $resumo['new_today'] }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">apareceram pela primeira vez</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Usuários afetados hoje</p>
            <p class="figure mt-2 text-3xl font-medium text-slate-900 dark:text-white">{{ $resumo['users_today'] }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">pessoas que bateram em algum erro</p>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="inline-flex rounded-lg bg-slate-100 p-0.5 text-sm dark:bg-white/10" role="group" aria-label="Filtrar erros">
            @foreach (['abertos' => 'Abertos', 'resolvidos' => 'Resolvidos', 'ignorados' => 'Ignorados', 'todos' => 'Todos'] as $valor => $rotulo)
                <button type="button" wire:click="$set('filtro', '{{ $valor }}')"
                        class="rounded-md px-3 py-1.5 {{ $filtro === $valor ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' : 'text-slate-500 dark:text-slate-400' }}">{{ $rotulo }}</button>
            @endforeach
        </div>
        <input type="search" wire:model.live.debounce.400ms="busca" placeholder="Buscar por mensagem, classe, arquivo ou rota" class="input w-full max-w-sm">
    </div>

    <div class="card overflow-hidden p-0">
        @if ($erros->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                {{ $filtro === 'abertos' && $busca === '' ? 'Nenhum erro aberto. Tudo calmo.' : 'Nenhum erro neste filtro.' }}
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="text-xs text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-5 py-2 font-medium">Erro</th>
                            <th class="px-3 py-2 font-medium">De onde</th>
                            <th class="px-3 py-2 text-right font-medium">Hoje</th>
                            <th class="px-3 py-2 text-right font-medium">Total</th>
                            <th class="px-3 py-2 text-right font-medium">Usuários</th>
                            <th class="px-3 py-2 font-medium">Última vez</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($erros as $erro)
                            <tr wire:key="erro-{{ $erro->id }}" class="align-top">
                                <td class="max-w-md px-5 py-3">
                                    <button type="button" wire:click="abrir({{ $erro->id }})" class="text-left">
                                        <span class="font-medium text-slate-900 dark:text-white">{{ $erro->shortClass() }}</span>
                                        @if ($erro->regressions > 0 && $erro->status === 'new')
                                            <span class="badge ml-1 bg-red-50 text-red-800 ring-1 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20">voltou</span>
                                        @endif
                                        @if ($erro->first_seen_at->isToday())
                                            <span class="badge ml-1 bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">novo</span>
                                        @endif
                                        <span class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{{ $erro->message }}</span>
                                    </button>
                                </td>
                                <td class="px-3 py-3 text-xs text-slate-500 dark:text-slate-400">{{ $erro->origin }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-slate-800 dark:text-slate-200">{{ (int) $erro->today_count }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ $erro->occurrences }}</td>
                                <td class="px-3 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ (int) $erro->users_count }}</td>
                                <td class="px-3 py-3 text-xs whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $erro->last_seen_at->diffForHumans() }}</td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    @if ($erro->status === 'new')
                                        <button type="button" wire:click="resolver({{ $erro->id }})" class="text-sm font-medium text-accent-700 hover:underline dark:text-accent-400">Resolver</button>
                                        <button type="button" wire:click="ignorar({{ $erro->id }})" class="ml-3 text-sm text-slate-500 hover:underline dark:text-slate-400">Ignorar</button>
                                    @else
                                        <span class="mr-3 text-xs text-slate-400">{{ $erro->status === 'resolved' ? 'resolvido' : 'ignorado' }}</span>
                                        <button type="button" wire:click="reabrir({{ $erro->id }})" class="text-sm text-slate-500 hover:underline dark:text-slate-400">Reabrir</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($erros->count() >= 200)
                <p class="border-t border-slate-100 px-5 py-2 text-xs text-slate-400 dark:border-white/10">Mostrando os 200 mais recentes: use a busca ou o filtro para achar os outros.</p>
            @endif
        @endif
    </div>

    {{-- Detalhe: o passo a passo do erro --}}
    <x-modal wire-model="detalheAberto" max-width="2xl">
        @if ($detalhe)
            @php $erro = $detalhe['erro']; @endphp
            <div class="space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $erro->exception_class }}</h2>
                        <p class="mt-1 text-sm break-words text-slate-600 dark:text-slate-300">{{ $erro->message }}</p>
                    </div>
                    <button type="button" wire:click="fechar" class="btn-ghost shrink-0 px-2 py-1 text-xs">Fechar</button>
                </div>

                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Onde</dt><dd class="font-mono text-xs break-all text-slate-800 dark:text-slate-200">{{ $erro->file }}:{{ $erro->line }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">De onde veio</dt><dd class="text-slate-800 dark:text-slate-200">{{ $erro->origin }} @if ($erro->http_method)<span class="text-xs text-slate-400">({{ $erro->http_method }})</span>@endif</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Primeira vez</dt><dd class="text-slate-800 dark:text-slate-200">{{ $erro->first_seen_at->format('d/m/Y H:i') }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Última vez</dt><dd class="text-slate-800 dark:text-slate-200">{{ $erro->last_seen_at->format('d/m/Y H:i') }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Ocorrências</dt><dd class="text-slate-800 dark:text-slate-200">{{ $erro->occurrences }} · {{ $detalhe['users'] }} {{ $detalhe['users'] === 1 ? 'usuário' : 'usuários' }}</dd></div>
                    <div><dt class="text-xs text-slate-500 dark:text-slate-400">Situação</dt><dd class="text-slate-800 dark:text-slate-200">
                        {{ ['new' => 'Aberto', 'resolved' => 'Resolvido', 'ignored' => 'Ignorado'][$erro->status] ?? $erro->status }}
                        @if ($erro->regressions > 0) · voltou {{ $erro->regressions }} {{ $erro->regressions === 1 ? 'vez' : 'vezes' }} depois de resolvido @endif
                    </dd></div>
                </dl>

                @if ($erro->trace)
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Passo a passo (só o código do Cerne)</p>
                        <pre class="mt-1 max-h-64 overflow-auto rounded-lg bg-slate-50 p-3 font-mono text-xs leading-relaxed text-slate-700 dark:bg-white/5 dark:text-slate-300">{{ $erro->trace }}</pre>
                    </div>
                @endif

                @if ($detalhe['dias']->isNotEmpty())
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Por dia</p>
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                            @foreach ($detalhe['dias'] as $d)
                                {{ \Carbon\CarbonImmutable::parse($d->day)->format('d/m') }}: {{ $d->count }}{{ ! $loop->last ? ' · ' : '' }}
                            @endforeach
                        </p>
                    </div>
                @endif

                <div class="flex flex-wrap gap-3 border-t border-slate-100 pt-3 dark:border-white/10">
                    @if ($erro->status === 'new')
                        <button type="button" wire:click="resolver({{ $erro->id }})" class="btn-primary px-3 py-1.5 text-sm">Marcar como resolvido</button>
                        <button type="button" wire:click="ignorar({{ $erro->id }})" class="btn-secondary px-3 py-1.5 text-sm">Ignorar</button>
                    @else
                        <button type="button" wire:click="reabrir({{ $erro->id }})" class="btn-secondary px-3 py-1.5 text-sm">Reabrir</button>
                    @endif
                </div>
            </div>
        @endif
    </x-modal>
</div>
