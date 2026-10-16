@use('App\Support\Money')

{{-- Gráficos de desempenho. Recebe $dados (InvestmentPerformanceService::forPeriod) e $meses (janela; 0 = tudo). --}}
@php
    $carteira = $dados['portfolio'];
    $resumo = $carteira['summary'];
    $proventos = $dados['incomes'];
    $composicao = $dados['allocation'];
    $pct = fn (?string $v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.').'%';
    $nomePeriodo = $meses === 0 ? 'todo o histórico' : 'últimos '.$meses.' meses';
@endphp

<div class="space-y-6">
{{-- Resumo ------------------------------------------------------------------------------- --}}
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="card p-5">
        <p class="eyebrow">Patrimônio</p>
        <p class="figure mt-2 text-2xl font-medium text-brand-800 dark:text-brand-300">{{ Money::format($composicao['total']) }}</p>
        <p class="mt-1 text-xs text-slate-400">carteira de hoje, pelos ativos cadastrados</p>
    </div>
    <div class="card p-5">
        <p class="eyebrow">Rentabilidade no período</p>
        <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ $pct($resumo['return']) }}</p>
        <p class="mt-1 text-xs text-slate-400">{{ $resumo['cdi'] !== null ? 'CDI no mesmo período: '.$pct($resumo['cdi']) : 'sem CDI para comparar' }}</p>
    </div>
    <div class="card p-5">
        <p class="eyebrow">Frente ao CDI</p>
        <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ $resumo['percent_of_cdi'] !== null ? $resumo['percent_of_cdi'].'% do CDI' : '—' }}</p>
        <p class="mt-1 text-xs text-slate-400">{{ $resumo['months_compared'] > 0 ? $resumo['months_above_cdi'].' de '.$resumo['months_compared'].' meses acima do CDI' : 'sem meses para comparar' }}</p>
    </div>
    <div class="card p-5">
        <p class="eyebrow">Proventos recebidos</p>
        <p class="figure mt-2 text-2xl font-medium text-accent-700 dark:text-accent-400">{{ Money::format($proventos['total']) }}</p>
        <p class="mt-1 text-xs text-slate-400">média de {{ Money::format($proventos['monthly_average']) }} por mês</p>
    </div>
</div>

{{-- Evolução do patrimônio ----------------------------------------------------------------- --}}
<section class="card p-5">
    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Evolução do patrimônio</h2>
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Saldo no fechamento de cada mês, conforme o relatório de performance da corretora (ele informa os últimos 12 meses).</p>
    <x-timeseries-chart class="mt-3 text-brand-700 dark:text-brand-300" :labels="$carteira['patrimony']['labels']" :series="[['valores' => $carteira['patrimony']['values'], 'tipo' => 'area', 'rotulo' => 'Patrimônio']]" formato="moeda" />
    @if ($carteira['patrimony']['first'] !== null && count($carteira['patrimony']['values']) > 1)
        <p class="mt-2 text-xs text-slate-400">De {{ Money::format($carteira['patrimony']['first']) }} para {{ Money::format($carteira['patrimony']['last']) }} (fechamento de {{ $carteira['patrimony']['last_label'] }}). Inclui os aportes e resgates do período.</p>
    @endif
</section>

{{-- Rentabilidade contra o CDI ------------------------------------------------------------- --}}
<section class="card p-5">
    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Rentabilidade acumulada contra o CDI</h2>
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Juros compostos mês a mês, começando em zero no primeiro mês do período. Só entram os meses em que a carteira e o CDI têm dado.</p>
    <div class="mt-3 flex flex-wrap gap-4 text-xs text-slate-600 dark:text-slate-300">
        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-5 rounded-full bg-accent-600 dark:bg-accent-400"></span>Carteira</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-5 rounded-full bg-slate-400"></span>CDI</span>
    </div>
    <div class="relative mt-2">
        <x-timeseries-chart class="text-slate-400" :labels="array_slice($carteira['months'], $carteira['sequence_offset'])" :series="[['valores' => array_slice($carteira['cumulative_cdi'], $carteira['sequence_offset']), 'tipo' => 'linha', 'classe' => 'text-slate-400', 'rotulo' => 'CDI acumulado'], ['valores' => array_slice($carteira['cumulative_return'], $carteira['sequence_offset']), 'tipo' => 'linha', 'classe' => 'text-accent-600 dark:text-accent-400', 'rotulo' => 'Carteira acumulada']]" formato="pct" />
    </div>
    @if ($resumo['sequence_start'] !== null)
        <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">O relatório da corretora não traz todos os meses antes de {{ $resumo['sequence_start'] }}. O acumulado, a comparação com o CDI e os melhores e piores meses consideram só a sequência contínua a partir dali.</p>
    @endif
    @if ($resumo['best'] !== null)
        <p class="mt-2 text-xs text-slate-400">Melhor mês: {{ $resumo['best']['label'] }} ({{ $pct($resumo['best']['value']) }}). Pior mês: {{ $resumo['worst']['label'] }} ({{ $pct($resumo['worst']['value']) }}).</p>
    @endif
</section>

<section class="card p-5">
    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Rentabilidade de cada mês</h2>
    <div class="mt-3 flex flex-wrap gap-4 text-xs text-slate-600 dark:text-slate-300">
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-accent-600 dark:bg-accent-400"></span>Carteira</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-slate-400"></span>CDI</span>
    </div>
    <x-timeseries-chart class="mt-2 text-slate-400" :labels="$carteira['months']" :series="[['valores' => $carteira['monthly_return'], 'tipo' => 'barra', 'classe' => 'text-accent-600 dark:text-accent-400', 'rotulo' => 'Carteira'], ['valores' => $carteira['monthly_cdi'], 'tipo' => 'barra', 'classe' => 'text-slate-400', 'rotulo' => 'CDI']]" formato="pct" />
</section>

{{-- Ganho em reais -------------------------------------------------------------------------- --}}
@if ($resumo['gain_total'] !== null)
    <section class="card p-5">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Ganho de cada mês</h2>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Quanto a carteira rendeu em reais, sem contar aportes e resgates. Total no período: {{ Money::format($resumo['gain_total']) }}.</p>
        <x-timeseries-chart class="mt-3 text-accent-600 dark:text-accent-400" :labels="$carteira['months']" :series="[['valores' => $carteira['gain'], 'tipo' => 'barra', 'rotulo' => 'Ganho']]" formato="moeda" />
    </section>
@endif

{{-- Composição ------------------------------------------------------------------------------ --}}
<section class="card p-5">
    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Composição da carteira</h2>
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Valor de hoje de cada grupo de ativos.</p>
    @if ($composicao['slices'] === [])
        <p class="mt-3 text-xs text-slate-400">Nenhum ativo com valor cadastrado.</p>
    @else
        <div class="mt-4 flex flex-col gap-5 sm:flex-row sm:items-center">
            <x-donut-chart :slices="$composicao['slices']" :size="160" :thickness="22" class="mx-auto sm:mx-0" />
            <div class="min-w-0 flex-1 space-y-1">
                @foreach ($composicao['slices'] as $f)
                    <div x-data="{ aberta: false }" wire:key="fatia-{{ $f['label'] }}">
                        <button type="button" @click="aberta = !aberta" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-slate-50 dark:hover:bg-white/5">
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background-color: {{ $f['color'] }}"></span>
                            <span class="min-w-0 flex-1 truncate text-xs text-slate-700 dark:text-slate-300">{{ $f['label'] }}</span>
                            <span class="shrink-0 text-xs tabular-nums text-slate-800 dark:text-slate-200">{{ number_format($f['pct'], 1, ',', '.') }}%</span>
                            <span class="shrink-0 text-xs tabular-nums text-slate-400">{{ Money::format($f['value']) }}</span>
                        </button>
                        <div x-show="aberta" x-transition x-cloak class="ml-6 space-y-1 pb-2">
                            @foreach ($f['assets'] as $a)
                                <div class="flex items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400">
                                    <span class="min-w-0 truncate">{{ $a['name'] }}</span>
                                    <span class="shrink-0 tabular-nums">{{ Money::format($a['value']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>

{{-- Proventos -------------------------------------------------------------------------------- --}}
<section class="card p-5">
    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Proventos recebidos por mês</h2>
    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Dividendos, rendimentos de FII e JCP líquido, no mês do pagamento ({{ $nomePeriodo }}).</p>
    <div class="mt-3 flex flex-wrap gap-4 text-xs text-slate-600 dark:text-slate-300">
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background-color:#0f766e"></span>Dividendos e rendimentos</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background-color:#d97706"></span>JCP</span>
    </div>
    <x-timeseries-chart class="mt-2" :labels="$proventos['months']" :series="[['tipo' => 'barra', 'empilhar' => true, 'cor' => '#0f766e', 'valores' => $proventos['dividends'], 'rotulo' => 'Dividendos e rendimentos'], ['tipo' => 'barra', 'empilhar' => true, 'cor' => '#d97706', 'valores' => $proventos['jcp'], 'rotulo' => 'JCP']]" formato="moeda" />

    @if ($proventos['by_asset'] !== [])
        <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Quem mais pagou</h3>
        @php $maiorProvento = max(array_map(fn ($a) => (float) $a['total'], $proventos['by_asset'])); @endphp
        <ul class="mt-2 space-y-2">
            @foreach ($proventos['by_asset'] as $a)
                <li class="text-xs">
                    <div class="flex items-center justify-between gap-3 text-slate-700 dark:text-slate-300">
                        <span class="min-w-0 truncate">{{ $a['name'] }}</span>
                        <span class="shrink-0 tabular-nums">{{ Money::format($a['total']) }}</span>
                    </div>
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                        <div class="h-full rounded-full bg-accent-600 dark:bg-accent-400" style="width: {{ $maiorProvento > 0 ? round(((float) $a['total'] / $maiorProvento) * 100) : 0 }}%"></div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<p class="text-xs text-slate-400">Patrimônio, rentabilidade e ganho mensais vêm do relatório de performance da corretora e só aparecem nos meses que ele traz. A composição usa o valor atual de cada ativo e os proventos usam as movimentações lançadas. Rentabilidade passada não garante rentabilidade futura.</p>
</div>
