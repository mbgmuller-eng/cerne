@use('App\Support\Money')
@use('App\Enums\InvestmentSector')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-900">Investimentos</h1>
        <p class="mt-1 text-sm text-stone-500">Carteira, rentabilidade e movimentações.</p>
    </div>

    {{-- Totais -------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Patrimônio investido</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800">{{ Money::format($total) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Custo de aquisição</p>
            <p class="figure mt-2 text-2xl font-medium text-stone-700">{{ Money::format($totalInvested) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Ganho não realizado</p>
            <p class="figure mt-2 text-2xl font-medium {{ (float) $totalGain < 0 ? 'text-red-700' : 'text-brand-800' }}">
                {{ Money::format($totalGain) }}
            </p>
        </div>
    </div>

    {{-- Abas ---------------------------------------------------------- --}}
    <div class="flex gap-1 border-b border-stone-200">
        @foreach (['portfolio' => 'Portfólio', 'performance' => 'Performance', 'transactions' => 'Transações'] as $chave => $rotulo)
            <button
                wire:click="setTab('{{ $chave }}')"
                @class([
                    '-mb-px border-b-2 px-4 py-2 text-sm transition',
                    'border-brand-700 font-medium text-brand-800' => $tab === $chave,
                    'border-transparent text-stone-500 hover:text-stone-800' => $tab !== $chave,
                ])
            >{{ $rotulo }}</button>
        @endforeach
    </div>

    @if ($tab === 'portfolio')
        {{-- Reservas -------------------------------------------------- --}}
        @if ($reserves->isNotEmpty())
            <section>
                <h2 class="text-sm font-semibold text-stone-900">Reservas</h2>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    @foreach ($reserves as $reserva)
                        <div class="card p-5">
                            <div class="flex items-baseline justify-between">
                                <p class="text-sm font-medium text-stone-800">{{ $reserva->reserve_type->label() }}</p>
                                <span class="text-xs text-stone-500">{{ $reserva->member->name }}</span>
                            </div>
                            <p class="mt-1 text-xs text-stone-500">{{ $reserva->reserve_type->description() }}</p>

                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-stone-100">
                                <div class="h-full rounded-full bg-brand-700" style="width: {{ $reserva->progressPercentage() }}%"></div>
                            </div>

                            <p class="mt-2 text-sm text-stone-700">
                                {{ Money::format($reserva->effectiveAmount()) }}
                                <span class="text-stone-400">de {{ Money::format($reserva->target_amount) }}</span>
                                @if ($reserva->isComplete())
                                    <span class="ml-1 text-brand-700">· completa</span>
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
                    <h2 class="text-sm font-semibold text-stone-900">
                        {{ InvestmentSector::from($setor)->label() }}
                    </h2>
                    <span class="text-sm tabular-nums text-stone-500">
                        {{ Money::format(Money::sum($ativos->pluck('current_amount'))) }}
                    </span>
                </div>

                <ul class="mt-2 card divide-y divide-stone-100">
                    @foreach ($ativos as $ativo)
                        <li class="flex items-center justify-between gap-4 px-5 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm text-stone-800">{{ $ativo->displayName() }}</p>
                                <p class="truncate text-xs text-stone-500">
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
                                <p class="text-sm tabular-nums text-stone-800">{{ Money::format($ativo->current_amount) }}</p>
                                @if ($ativo->invested_amount && (float) $ativo->invested_amount > 0)
                                    @php $ganho = $ativo->unrealizedGain(); @endphp
                                    <p class="text-xs tabular-nums {{ (float) $ganho < 0 ? 'text-red-700' : 'text-brand-700' }}">
                                        {{ (float) $ganho >= 0 ? '+' : '' }}{{ Money::format($ganho) }}
                                    </p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-12 text-center">
                <p class="text-sm text-stone-600">Nenhum investimento cadastrado.</p>
            </div>
        @endforelse

    @elseif ($tab === 'performance')
        @if ($performance->isEmpty())
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-12 text-center">
                <p class="text-sm text-stone-600">Nenhuma rentabilidade registrada.</p>
                <p class="mt-1 text-xs text-stone-400">Os relatórios de rentabilidade chegam pela importação de PDF.</p>
            </div>
        @else
            <ul class="card divide-y divide-stone-100">
                @foreach ($performance as $p)
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-stone-800">
                                {{ $p->isPortfolioWide() ? 'Carteira consolidada' : $p->investment->displayName() }}
                            </p>
                            <p class="text-xs text-stone-500">
                                {{ $p->periodLabel() }}
                                @if ($p->benchmark) · vs {{ $p->benchmark->label() }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm tabular-nums {{ (float) $p->return_percentage < 0 ? 'text-red-700' : 'text-brand-700' }}">
                                {{ number_format((float) $p->return_percentage, 2, ',', '.') }}%
                            </p>
                            @if ($p->vs_benchmark !== null)
                                <p class="text-xs {{ $p->beatBenchmark() ? 'text-brand-600' : 'text-stone-400' }}">
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
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-12 text-center">
                <p class="text-sm text-stone-600">Nenhuma movimentação registrada.</p>
            </div>
        @else
            <ul class="card divide-y divide-stone-100">
                @foreach ($transactions as $tx)
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-stone-800">
                                {{ $tx->transaction_type->label() }} · {{ $tx->investment->displayName() }}
                            </p>
                            <p class="truncate text-xs text-stone-500">
                                {{ $tx->operation_date->format('d/m/Y') }}
                                @if ($tx->quantity && (float) $tx->quantity > 0)
                                    · {{ rtrim(rtrim($tx->quantity, '0'), '.') }} a {{ Money::format($tx->unit_price) }}
                                @endif
                                @if ($tx->broker_fee && (float) $tx->broker_fee > 0)
                                    · taxa {{ Money::format($tx->broker_fee) }}
                                @endif
                            </p>
                        </div>
                        <p class="shrink-0 text-sm tabular-nums text-stone-800">{{ Money::format($tx->net_amount) }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

</div>
