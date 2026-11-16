@use('App\Support\Money')

<div data-budget-summary>
    @if ($mostrar)
        @if ($relatorio === null)
            <a href="{{ route('budget.index') }}" class="card card-hover flex items-center justify-between gap-3 p-4" data-budget-summary-empty>
                <span>
                    <span class="eyebrow block">Regra de orçamento</span>
                    <span class="mt-1 block text-sm text-slate-600 dark:text-slate-300">Defina quanto da receita vai para essencial, supérfluo e investimentos e acompanhe o mês.</span>
                </span>
                <span class="shrink-0 text-sm font-medium text-brand-700 dark:text-brand-300">Definir</span>
            </a>
        @else
            <a href="{{ route('budget.index', ['mes' => sprintf('%04d-%02d', $ano, $mes)]) }}" class="card card-hover block p-5">
                <div class="flex items-baseline justify-between gap-2">
                    <p class="eyebrow">Regra do mês</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">receita de {{ Money::format($relatorio['income']['total']) }}</p>
                </div>
                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    @foreach (['essential', 'discretionary', 'investment'] as $area)
                        @php
                            $a = $relatorio['areas'][$area];
                            $cor = match ($a['status']) {
                                'over' => 'bg-red-600 dark:bg-red-400',
                                'partial' => 'bg-amber-500 dark:bg-amber-400',
                                default => 'bg-brand-700 dark:bg-brand-400',
                            };
                        @endphp
                        <div data-summary-area="{{ $area }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-2 text-xs">
                                <span class="font-medium text-slate-700 dark:text-slate-300">{{ $a['name'] }} · {{ rtrim(rtrim(number_format((float) $a['percent'], 2, ',', ''), '0'), ',') }}%</span>
                                <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ Money::format($a['realized']) }} de {{ Money::format($a['target']) }}</span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700"><div class="h-full rounded-full {{ $cor }}" style="width: {{ $a['progress'] }}%"></div></div>
                            <p class="mt-1 text-xs {{ $a['status'] === 'over' ? 'text-red-700 dark:text-red-400' : 'text-slate-500 dark:text-slate-400' }}">
                                @if ($a['direction'] === 'max')
                                    {{ $a['status'] === 'over' ? 'Passou '.Money::format($a['over']) : 'Dentro do limite' }}
                                @else
                                    {{ bccomp($a['missing'], '0', 2) > 0 ? 'Faltam '.Money::format($a['missing']) : 'Meta batida' }}
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            </a>
        @endif
    @endif
</div>
