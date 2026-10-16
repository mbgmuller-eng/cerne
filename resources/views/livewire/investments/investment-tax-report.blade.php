@use('App\Support\Money')

@php
    $mesesNome = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    $qtd = fn (string $v) => rtrim(rtrim($v, '0'), '.') ?: '0';
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Imposto de renda</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Posição, proventos e vendas dos investimentos, para conferir a declaração.</p>
        </div>
        <a href="{{ route('investments.index') }}" class="btn-ghost px-3 py-1.5 text-sm">Voltar aos investimentos</a>
    </div>

    <div class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Ano-base</label>
            <select wire:model.live="ano" class="select mt-1.5">
                @foreach ($anos as $a)
                    <option value="{{ $a }}">{{ $a }}</option>
                @endforeach
            </select>
        </div>
        @if ($membros->count() > 1)
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa (cada um declara no seu CPF)</label>
                <select wire:model.live="pessoa" class="select mt-1.5">
                    @foreach ($membros as $m)
                        <option value="{{ $m->id }}">{{ $m->name }}</option>
                    @endforeach
                    <option value="todos">Todos juntos</option>
                </select>
            </div>
        @endif
        <div class="ml-auto flex gap-2">
            <button type="button" wire:click="baixarCsv" class="btn-secondary px-3 py-1.5">Baixar CSV</button>
            <button type="button" wire:click="baixarPdf" class="btn-secondary px-3 py-1.5">Baixar PDF</button>
        </div>
    </div>

    <p class="rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
        É uma conferência com o que está registrado no Cerne: não calcula DARF, não compensa prejuízos e não separa day trade, e não substitui o seu contador.
        Confira se todas as compras, vendas e proventos do ano estão lançados.
    </p>

    @if (! $relatorio['has_data'])
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma movimentação de investimentos em {{ $relatorio['year'] }} para {{ $nomeDaPessoa }}.</p>
        </div>
    @else
        {{-- Bens e direitos ---------------------------------------------------------------------- --}}
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Bens e direitos</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Cotas em 31/12/{{ $relatorio['year'] }} pelo custo de aquisição (não pelo valor de mercado), com a situação do ano anterior para comparar.</p>

            @if ($relatorio['positions'] === [])
                <p class="mt-3 text-xs text-slate-400">Nenhuma posição em cotas em 31/12/{{ $relatorio['year'] }}.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr class="text-left text-xs text-slate-500 dark:text-slate-400">
                                <th class="py-1.5 pr-3 font-medium">Ativo</th>
                                <th class="px-3 py-1.5 text-right font-medium">Cotas</th>
                                <th class="px-3 py-1.5 text-right font-medium">Preço médio</th>
                                <th class="px-3 py-1.5 text-right font-medium">Custo em 31/12/{{ $relatorio['year'] }}</th>
                                <th class="py-1.5 pl-3 text-right font-medium">Custo em 31/12/{{ $relatorio['year'] - 1 }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($relatorio['positions'] as $p)
                                <tr wire:key="pos-{{ $p['investment']->id }}">
                                    <td class="py-2 pr-3 text-slate-800 dark:text-slate-200">{{ $p['investment']->displayName() }} <span class="text-xs text-slate-400">· {{ $p['investment']->asset_class->label() }}</span></td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $qtd($p['quantity']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ Money::format($p['average_price']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-medium">{{ Money::format($p['cost']) }}</td>
                                    <td class="py-2 pl-3 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ Money::format($p['previous_cost']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-slate-200 font-medium dark:border-white/10">
                                <td class="py-2 pr-3" colspan="3">Total</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ Money::format($relatorio['positions_cost']) }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ Money::format($relatorio['previous_cost']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        {{-- Proventos ------------------------------------------------------------------------------ --}}
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Proventos recebidos em {{ $relatorio['year'] }}</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Dividendos e rendimentos de FII costumam ser isentos; o JCP é tributado na fonte. Amortização não é rendimento: ela reduz o custo da aplicação.</p>

            @if ($relatorio['incomes'] === [])
                <p class="mt-3 text-xs text-slate-400">Nenhum provento registrado no ano.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[34rem] text-sm">
                        <thead>
                            <tr class="text-left text-xs text-slate-500 dark:text-slate-400">
                                <th class="py-1.5 pr-3 font-medium">Natureza</th>
                                <th class="px-3 py-1.5 font-medium">Ativo</th>
                                <th class="px-3 py-1.5 text-right font-medium">Bruto</th>
                                <th class="px-3 py-1.5 text-right font-medium">Imposto retido</th>
                                <th class="py-1.5 pl-3 text-right font-medium">Líquido</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($relatorio['incomes'] as $p)
                                <tr wire:key="prov-{{ $p['investment']->id }}-{{ $p['kind'] }}">
                                    <td class="py-2 pr-3 text-slate-600 dark:text-slate-400">{{ $p['label'] }}</td>
                                    <td class="px-3 py-2 text-slate-800 dark:text-slate-200">{{ $p['investment']->displayName() }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ Money::format($p['gross']) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ Money::format($p['tax']) }}</td>
                                    <td class="py-2 pl-3 text-right tabular-nums font-medium">{{ Money::format($p['net']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="mt-4 grid gap-2 sm:grid-cols-2">
                    @foreach ($relatorio['income_totals'] as $t)
                        <div class="flex justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-xs dark:bg-white/5">
                            <dt class="text-slate-600 dark:text-slate-300">{{ $t['label'] }}</dt>
                            <dd class="font-medium tabular-nums text-slate-900 dark:text-white">{{ Money::format($t['net']) }} <span class="font-normal text-slate-400">líquido</span></dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </section>

        {{-- Vendas --------------------------------------------------------------------------------- --}}
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Vendas em {{ $relatorio['year'] }}</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">O resultado é o valor líquido da venda menos o custo médio das cotas vendidas. Vendas de ações até R$ 20 mil no mês (e de criptoativos até R$ 35 mil) têm o ganho isento.</p>

            @if ($relatorio['months'] === [])
                <p class="mt-3 text-xs text-slate-400">Nenhuma venda registrada no ano.</p>
            @else
                <div class="mt-3 space-y-5">
                    @foreach ($relatorio['months'] as $mes)
                        <div wire:key="mes-{{ $mes['month'] }}">
                            <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $mesesNome[$mes['month']] }}</p>

                            <ul class="mt-1.5 space-y-1">
                                @foreach ($mes['groups'] as $g)
                                    <li class="flex flex-wrap items-center justify-between gap-2 text-xs">
                                        <span class="text-slate-600 dark:text-slate-300">{{ $g['label'] }}: vendeu {{ Money::format($g['sold']) }}@if ($g['limit']) <span class="text-slate-400">(limite de isenção {{ Money::format($g['limit']) }})</span>@endif</span>
                                        <span class="flex items-center gap-2">
                                            <span class="tabular-nums {{ bccomp($g['result'], '0', 2) < 0 ? 'text-slate-500 dark:text-slate-400' : 'text-slate-900 dark:text-white' }}">resultado {{ Money::format($g['result']) }}</span>
                                            @if ($g['limit'])
                                                <span @class(['badge', 'bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300' => $g['exempt'], 'bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => ! $g['exempt']])>{{ $g['exempt'] ? 'dentro do limite' : 'acima do limite' }}</span>
                                            @elseif ($g['key'] === 'renda_fixa')
                                                <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">imposto já retido</span>
                                            @else
                                                <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">sem isenção</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            <details class="mt-2 text-xs">
                                <summary class="cursor-pointer text-slate-500 dark:text-slate-400">{{ count($mes['sales']) }} {{ count($mes['sales']) === 1 ? 'venda' : 'vendas' }}</summary>
                                <ul class="mt-1.5 space-y-0.5 text-slate-600 dark:text-slate-300">
                                    @foreach ($mes['sales'] as $v)
                                        <li>{{ $v['date']->format('d/m') }} · {{ $v['investment']->displayName() }} · {{ $qtd($v['quantity']) }} cotas · {{ Money::format($v['net']) }} · custo {{ Money::format($v['cost']) }} · resultado {{ Money::format($v['result']) }}</li>
                                    @endforeach
                                </ul>
                            </details>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>
