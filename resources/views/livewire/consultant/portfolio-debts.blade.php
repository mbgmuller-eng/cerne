@use('App\Support\Money')

<div class="space-y-6">
    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Dívidas da carteira</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">O que cada cliente vinculado deve, quanto paga de parcela neste mês e cada contrato.</p>
    </div>

    @if ($clientes->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum cliente vinculado tem dívida cadastrada ainda.</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card p-5">
                <p class="eyebrow">Total devedor da carteira</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($totais['balance']) }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ $totais['clients_with_debt'] }} {{ $totais['clients_with_debt'] === 1 ? 'cliente' : 'clientes' }} com dívida</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Parcelas deste mês</p>
                <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($totais['month']) }}</p>
                <p class="mt-1 text-xs text-slate-400">somando todos os clientes</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Cartas de consórcio</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($totais['credit']) }}</p>
                <p class="mt-1 text-xs text-slate-400">ainda não contempladas; não são dívida</p>
            </div>
        </div>

        <div class="space-y-3" x-data="{ todos: true }">
            <div class="flex items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400">
                <p>Clique em um contrato para abrir a dívida do cliente.</p>
                <button type="button" @click="todos = ! todos" class="btn-ghost px-3 py-1 text-xs" x-text="todos ? 'Recolher todos' : 'Expandir todos'"></button>
            </div>

            @foreach ($clientes as $nome => $c)
                {{-- Abre já expandido: o contrato está a um clique. O botão do topo recolhe ou expande todos de uma vez. --}}
                <div x-data="{ open: true }" x-effect="open = todos" class="card overflow-hidden" wire:key="cliente-{{ $c['profile_id'] }}">
                    <button type="button" @click="open = ! open" class="flex w-full flex-wrap items-center justify-between gap-3 px-5 py-4 text-left">
                        <p class="font-display text-lg font-semibold text-slate-900 dark:text-white sm:text-xl">{{ $nome }}</p>
                        <div class="text-right">
                            <p class="figure text-sm font-semibold text-slate-800 dark:text-slate-200">{{ Money::format($c['balance']) }}</p>
                            <p class="text-xs text-slate-400">
                                {{ count($c['rows']) }} {{ count($c['rows']) === 1 ? 'contrato' : 'contratos' }}
                                @if (bccomp($c['month'], '0', 2) > 0) · {{ Money::format($c['month']) }} no mês @endif
                            </p>
                        </div>
                    </button>

                    <ul x-show="open" x-cloak class="divide-y divide-slate-100 border-t border-slate-100 px-5 dark:divide-white/10 dark:border-white/10">
                        @foreach ($c['rows'] as $l)
                            <li wire:key="divida-{{ $l['debt']->id }}">
                                {{-- POST puro (como "Abrir perfil"): entra no perfil do cliente e cai direto na dívida. --}}
                                <form method="POST" action="{{ route('profile.switch', $c['profile_id']) }}">
                                    @csrf
                                    <input type="hidden" name="destino" value="dividas">
                                    <input type="hidden" name="divida" value="{{ $l['debt']->id }}">
                                    <button type="submit" class="-mx-3 flex w-[calc(100%+1.5rem)] flex-wrap items-center justify-between gap-3 rounded-lg px-3 py-3 text-left transition hover:bg-slate-50 focus-visible:bg-slate-50 dark:hover:bg-white/5 dark:focus-visible:bg-white/5">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-200">
                                                {{ $l['debt']->name }}
                                                @if ($l['debt']->status->isUnscheduled())
                                                    <span class="badge ml-1 bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-300">{{ $l['debt']->status->label() }}</span>
                                                @endif
                                            </span>
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                                {{ implode(' · ', array_filter([$l['debt']->kind->label(), $l['debt']->creditor, $l['member_name']])) }}
                                            </span>
                                            @if ($l['next_due'])
                                                <span class="block text-xs text-slate-400">próxima parcela em {{ $l['next_due']->format('d/m/Y') }}</span>
                                            @endif
                                        </span>
                                        <span class="flex shrink-0 items-center gap-3">
                                            <span class="text-right">
                                                @if ($l['credit'] !== null)
                                                    <span class="figure block text-sm text-slate-800 dark:text-slate-200">{{ Money::format($l['credit']) }}</span>
                                                    <span class="block text-xs text-slate-400">carta (não contemplada)</span>
                                                @else
                                                    <span class="figure block text-sm text-slate-800 dark:text-slate-200">{{ Money::format($l['balance']) }}</span>
                                                    <span class="block text-xs text-slate-400">{{ $l['debt']->balanceLabel() }}</span>
                                                @endif
                                            </span>
                                            <x-nav-icon name="chevron" class="h-4 w-4 -rotate-90 text-slate-400" />
                                        </span>
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</div>
