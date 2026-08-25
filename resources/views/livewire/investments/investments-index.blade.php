@use('App\Support\Money')
@use('App\Enums\InvestmentSector')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Investimentos</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Carteira, rentabilidade e movimentações.</p>
    </div>

    {{-- Totais -------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Patrimônio investido</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800 dark:text-brand-300">{{ Money::format($total) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Custo de aquisição</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-700 dark:text-slate-300">{{ Money::format($totalInvested) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Ganho não realizado</p>
            <p class="figure mt-2 text-2xl font-medium {{ (float) $totalGain < 0 ? 'text-red-700 dark:text-red-400' : 'text-brand-800 dark:text-brand-300' }}">
                {{ Money::format($totalGain) }}
            </p>
        </div>
    </div>

    {{-- Abas ---------------------------------------------------------- --}}
    <div class="flex gap-1 border-b border-slate-200 dark:border-white/10">
        @foreach (['portfolio' => 'Portfólio', 'performance' => 'Performance', 'transactions' => 'Transações'] as $chave => $rotulo)
            <button
                wire:click="setTab('{{ $chave }}')"
                @class([
                    '-mb-px border-b-2 px-4 py-2 text-sm transition',
                    'border-brand-700 dark:border-brand-400 font-medium text-brand-800 dark:text-brand-300' => $tab === $chave,
                    'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200' => $tab !== $chave,
                ])
            >{{ $rotulo }}</button>
        @endforeach
    </div>

    @if ($tab === 'portfolio')
        {{-- Reservas -------------------------------------------------- --}}
        @if ($reserves->isNotEmpty())
            <section>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Reservas</h2>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    @foreach ($reserves as $reserva)
                        <div class="card p-5">
                            <div class="flex items-baseline justify-between">
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $reserva->reserve_type->label() }}</p>
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ $reserva->member->name }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $reserva->reserve_type->description() }}</p>

                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                                <div class="h-full rounded-full bg-brand-700 dark:bg-brand-400" style="width: {{ $reserva->progressPercentage() }}%"></div>
                            </div>

                            <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                                {{ Money::format($reserva->effectiveAmount()) }}
                                <span class="text-slate-400">de {{ Money::format($reserva->target_amount) }}</span>
                                @if ($reserva->isComplete())
                                    <span class="ml-1 text-brand-700 dark:text-brand-300">· completa</span>
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Carteira por setor ---------------------------------------- --}}
        @forelse ($bySector as $setor => $ativos)
            <section>
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">
                        {{ InvestmentSector::from($setor)->label() }}
                    </h2>
                    <span class="text-sm tabular-nums text-slate-500 dark:text-slate-400">
                        {{ Money::format(Money::sum($ativos->pluck('current_amount'))) }}
                    </span>
                </div>

                @if ($setor === 'retirement')
                    {{-- Previdência é um contrato que evolui no tempo, não um
                         ativo com cota — o gráfico é mais informativo que a
                         linha de lista usada para os outros setores. --}}
                    <div class="mt-2 space-y-4">
                        @foreach ($ativos as $ativo)
                            @php
                                $pct = $ativo->gainPercentage();
                                $anualizado = $ativo->annualizedReturnPercentage();
                                $dias = $ativo->daysHeld();
                            @endphp
                            <div class="card p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-900 dark:text-white">
                                            {{ $ativo->institution ?? $ativo->name }} · {{ $ativo->asset_class->label() }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                            {{ $ativo->name }}
                                            @if ($ativo->return_rate) · {{ $ativo->return_rate }} @endif
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="figure text-lg font-semibold text-slate-900 dark:text-white">
                                            {{ Money::format($ativo->current_amount) }}
                                        </p>
                                        @if ($pct !== null)
                                            <p @class([
                                                'text-xs font-medium',
                                                'text-brand-700 dark:text-brand-300' => $pct >= 0,
                                                'text-red-700 dark:text-red-400' => $pct < 0,
                                            ])>
                                                {{ $pct >= 0 ? '+' : '' }}{{ number_format($pct, 2, ',', '.') }}%
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <x-sparkline :values="$snapshotHistory[$ativo->id] ?? []" />
                                </div>

                                <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    @if ($ativo->invested_amount)
                                        <span>Capital inicial: {{ Money::format($ativo->invested_amount) }}</span>
                                    @endif
                                    @if ($dias !== null)
                                        <span>Dias corridos: {{ $dias }}</span>
                                    @endif
                                    @if ($anualizado !== null)
                                        <span>Anualizado: {{ number_format($anualizado, 2, ',', '.') }}%</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <ul class="mt-2 card divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($ativos as $ativo)
                            <li class="flex items-center justify-between gap-4 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $ativo->displayName() }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ $ativo->asset_class->label() }}
                                        @if ($ativo->institution) · {{ $ativo->institution }} @endif
                                        @if ($ativo->quantity && (float) $ativo->quantity > 0)
                                            · {{ rtrim(rtrim($ativo->quantity, '0'), '.') }} cotas
                                            a {{ Money::format($ativo->average_price) }}
                                        @endif
                                        @if ($ativo->return_rate) · {{ $ativo->return_rate }} @endif
                                    </p>
                                </div>

                                <div class="shrink-0 text-right">
                                    <p class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($ativo->current_amount) }}</p>
                                    @if ($ativo->invested_amount && (float) $ativo->invested_amount > 0)
                                        @php $ganho = $ativo->unrealizedGain(); @endphp
                                        <p class="text-xs tabular-nums {{ (float) $ganho < 0 ? 'text-red-700 dark:text-red-400' : 'text-brand-700 dark:text-brand-300' }}">
                                            {{ (float) $ganho >= 0 ? '+' : '' }}{{ Money::format($ganho) }}
                                        </p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum investimento cadastrado.</p>
            </div>
        @endforelse

    @elseif ($tab === 'performance')
        @if ($performance->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma rentabilidade registrada.</p>
                <p class="mt-1 text-xs text-slate-400">Os relatórios de rentabilidade chegam pela importação de PDF.</p>
            </div>
        @else
            <ul class="card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($performance as $p)
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">
                                {{ $p->isPortfolioWide() ? 'Carteira consolidada' : $p->investment->displayName() }}
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ $p->periodLabel() }}
                                @if ($p->benchmark) · vs {{ $p->benchmark->label() }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm tabular-nums {{ (float) $p->return_percentage < 0 ? 'text-red-700 dark:text-red-400' : 'text-brand-700 dark:text-brand-300' }}">
                                {{ number_format((float) $p->return_percentage, 2, ',', '.') }}%
                            </p>
                            @if ($p->vs_benchmark !== null)
                                <p class="text-xs {{ $p->beatBenchmark() ? 'text-brand-600 dark:text-brand-400' : 'text-slate-400' }}">
                                    {{ $p->beatBenchmark() ? 'acima' : 'abaixo' }} do índice
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

    @else
        @if ($transactions->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma movimentação registrada.</p>
            </div>
        @else
            <ul class="card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($transactions as $tx)
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">
                                {{ $tx->transaction_type->label() }} · {{ $tx->investment->displayName() }}
                            </p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ $tx->operation_date->format('d/m/Y') }}
                                @if ($tx->quantity && (float) $tx->quantity > 0)
                                    · {{ rtrim(rtrim($tx->quantity, '0'), '.') }} a {{ Money::format($tx->unit_price) }}
                                @endif
                                @if ($tx->broker_fee && (float) $tx->broker_fee > 0)
                                    · taxa {{ Money::format($tx->broker_fee) }}
                                @endif
                            </p>
                        </div>
                        <p class="shrink-0 text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($tx->net_amount) }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

</div>
