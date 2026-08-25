@use('App\Support\Money')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Sonhos &amp; Objetivos</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Ordenados por prioridade.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Soma dos objetivos</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($totalTarget) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Já acumulado</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800 dark:text-brand-300">{{ Money::format($totalAccumulated) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Guardar por mês</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($totalMonthlyNeeded) }}</p>
            <p class="mt-1 text-xs text-slate-400">Para cumprir os prazos definidos.</p>
        </div>
    </div>

    @if ($goals->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum objetivo cadastrado.</p>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($goals as $objetivo)
                <li class="card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-800 text-xs font-medium text-white">
                                    {{ $objetivo->priority }}
                                </span>
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $objetivo->name }}</p>
                            </div>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $objetivo->funding_method->label() }}
                                @if ($objetivo->target_date) · até {{ $objetivo->target_date->format('m/Y') }} @endif
                                @if ($objetivo->member) · {{ $objetivo->member->name }} @else · do casal @endif
                                @if ($objetivo->linkedInvestment) · vinculado a {{ $objetivo->linkedInvestment->name }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($objetivo->estimated_value) }}</p>
                            @if ($mensal = $objetivo->monthlyNeeded())
                                <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ Money::format($mensal) }}/mês</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                        <div class="h-full rounded-full bg-brand-700 dark:bg-brand-400" style="width: {{ $objetivo->progressPercentage() }}%"></div>
                    </div>

                    <div class="mt-2 flex items-baseline justify-between">
                        <p class="text-sm text-slate-700 dark:text-slate-300">
                            {{ Money::format($objetivo->accumulated()) }}
                            <span class="text-slate-400">· {{ number_format($objetivo->progressPercentage(), 1, ',', '.') }}%</span>
                        </p>
                        @if ($objetivo->isAchieved())
                            <span class="text-xs text-brand-700 dark:text-brand-300">objetivo alcançado</span>
                        @else
                            <span class="text-xs text-slate-400">faltam {{ Money::format($objetivo->remaining()) }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($achieved->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Conquistados</h2>
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($achieved as $objetivo)
                    <li class="flex items-center justify-between px-5 py-3">
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $objetivo->name }}</p>
                        <p class="text-sm tabular-nums text-slate-500 dark:text-slate-400">{{ Money::format($objetivo->estimated_value) }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

</div>
