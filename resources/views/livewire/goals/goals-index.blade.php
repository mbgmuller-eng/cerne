@use('App\Support\Money')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-900">Sonhos &amp; Objetivos</h1>
        <p class="mt-1 text-sm text-stone-500">Ordenados por prioridade.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Soma dos objetivos</p>
            <p class="figure mt-2 text-2xl font-medium text-stone-900">{{ Money::format($totalTarget) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Já acumulado</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800">{{ Money::format($totalAccumulated) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Guardar por mês</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700">{{ Money::format($totalMonthlyNeeded) }}</p>
            <p class="mt-1 text-xs text-stone-400">Para cumprir os prazos definidos.</p>
        </div>
    </div>

    @if ($goals->isEmpty())
        <div class="rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-12 text-center">
            <p class="text-sm text-stone-600">Nenhum objetivo cadastrado.</p>
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
                                <p class="truncate text-sm font-medium text-stone-800">{{ $objetivo->name }}</p>
                            </div>
                            <p class="mt-1 text-xs text-stone-500">
                                {{ $objetivo->funding_method->label() }}
                                @if ($objetivo->target_date) · até {{ $objetivo->target_date->format('m/Y') }} @endif
                                @if ($objetivo->member) · {{ $objetivo->member->name }} @else · do casal @endif
                                @if ($objetivo->linkedInvestment) · vinculado a {{ $objetivo->linkedInvestment->name }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm tabular-nums text-stone-800">{{ Money::format($objetivo->estimated_value) }}</p>
                            @if ($mensal = $objetivo->monthlyNeeded())
                                <p class="text-xs tabular-nums text-stone-500">{{ Money::format($mensal) }}/mês</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-stone-100">
                        <div class="h-full rounded-full bg-brand-700" style="width: {{ $objetivo->progressPercentage() }}%"></div>
                    </div>

                    <div class="mt-2 flex items-baseline justify-between">
                        <p class="text-sm text-stone-700">
                            {{ Money::format($objetivo->accumulated()) }}
                            <span class="text-stone-400">· {{ number_format($objetivo->progressPercentage(), 1, ',', '.') }}%</span>
                        </p>
                        @if ($objetivo->isAchieved())
                            <span class="text-xs text-brand-700">objetivo alcançado</span>
                        @else
                            <span class="text-xs text-stone-400">faltam {{ Money::format($objetivo->remaining()) }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($achieved->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-stone-900">Conquistados</h2>
            <ul class="mt-3 card divide-y divide-stone-100">
                @foreach ($achieved as $objetivo)
                    <li class="flex items-center justify-between px-5 py-3">
                        <p class="text-sm text-stone-600">{{ $objetivo->name }}</p>
                        <p class="text-sm tabular-nums text-stone-500">{{ Money::format($objetivo->estimated_value) }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

</div>
