@use('App\Support\Money')
@use('App\Enums\Necessity')

@php
    $p = $dados['patrimonio'];
    $m = $dados['mes'];
    // O cache guarda array puro (ver DashboardService::overview); a
    // Collection é reconstituída aqui, fora do (un)serialize do cache.
    $evolucao = collect($dados['evolucao']);
    $alertas = $dados['alertas'];
    $alertas['itens'] = collect($alertas['itens']);
    $maximo = max($evolucao->max('receitas'), $evolucao->max('despesas'), 1);
@endphp

<div class="space-y-8">

    {{-- Cabeçalho da página ------------------------------------------- --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow">{{ now()->translatedFormat('l, j \d\e F') }}</p>
            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">
                Olá, {{ explode(' ', $member?->name ?? auth()->user()->name)[0] }}
            </h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $profile->profile_name }} · {{ $profile->profile_type->label() }}
            </p>
        </div>
    </div>

    {{-- Patrimônio líquido — o número-manchete --------------------------- --}}
    <div class="card relative overflow-hidden bg-brand-800 p-6 text-white sm:p-8">
        <div class="pointer-events-none absolute -top-24 -right-24 h-64 w-64 rounded-full bg-brand-600/50 dark:bg-brand-400/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-16 h-64 w-64 rounded-full bg-brand-950/70 blur-3xl"></div>

        <div class="relative">
            <p class="eyebrow text-brand-200">Patrimônio líquido</p>
            <p class="figure mt-2 text-4xl font-medium sm:text-5xl {{ (float) $p['liquido'] < 0 ? 'text-red-200' : '' }}">
                {{ Money::format($p['liquido']) }}
            </p>

            <div class="mt-6 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-3">
                <div>
                    <p class="text-brand-200">Investimentos</p>
                    <p class="figure mt-0.5 text-lg">{{ Money::format($p['investimentos']) }}</p>
                </div>
                <div>
                    <p class="text-brand-200">Em conta</p>
                    <p class="figure mt-0.5 text-lg">{{ Money::format($p['contas']) }}</p>
                </div>
                <div>
                    <p class="text-brand-200">Faturas em aberto</p>
                    {{-- Já foi gasto, ainda que não pago: entra como dívida. --}}
                    <p class="figure mt-0.5 text-lg text-amber-200">− {{ Money::format($p['faturas']) }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Mês corrente ----------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Receitas do mês</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-700 dark:text-brand-300">{{ Money::format($m['receitas']) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Despesas do mês</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($m['despesas']) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Sobra</p>
            <p class="figure mt-2 text-2xl font-medium {{ (float) $m['sobra'] < 0 ? 'text-red-700 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">
                {{ Money::format($m['sobra']) }}
            </p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                taxa de poupança de {{ number_format($m['taxa_poupanca'], 1, ',', '.') }}%
            </p>
        </div>
    </div>

    {{-- Evolução ---------------------------------------------------------- --}}
    <div class="card p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="eyebrow">Receitas e despesas · últimos {{ $evolucao->count() }} meses</p>
            <div class="flex gap-4 text-xs text-slate-500 dark:text-slate-400">
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-brand-600 dark:bg-brand-400"></span>receitas</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500"></span>despesas</span>
            </div>
        </div>

        <div class="mt-6 flex items-end gap-1.5 sm:gap-3" style="height: 160px">
            @foreach ($evolucao as $mes)
                <div class="group flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5">
                    <div class="flex h-full w-full items-end justify-center gap-0.5">
                        <div
                            class="w-1/2 max-w-3 rounded-t-md bg-brand-600 dark:bg-brand-400 transition-colors group-hover:bg-brand-500 dark:group-hover:bg-brand-300"
                            style="height: {{ max(2, ((float) $mes['receitas'] / $maximo) * 100) }}%"
                            title="Receitas: {{ Money::format($mes['receitas']) }}"
                        ></div>
                        <div
                            class="w-1/2 max-w-3 rounded-t-md bg-amber-500 transition-colors group-hover:bg-amber-400"
                            style="height: {{ max(2, ((float) $mes['despesas'] / $maximo) * 100) }}%"
                            title="Despesas: {{ Money::format($mes['despesas']) }}"
                        ></div>
                    </div>
                    <span class="text-[10px] whitespace-nowrap text-slate-400">{{ $mes['rotulo'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Alertas ------------------------------------------------------------ --}}
    @if ($alertas['itens']->isNotEmpty())
        <div class="card border-l-4 border-l-amber-500 p-5">
            <div class="flex items-baseline justify-between">
                <p class="text-sm font-medium text-slate-900 dark:text-white">
                    Vencendo nos próximos {{ $alertas['dias'] }} dias
                </p>
                <span class="figure text-sm text-slate-700 dark:text-slate-300">{{ Money::format($alertas['total']) }}</span>
            </div>

            <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($alertas['itens'] as $item)
                    <li class="flex items-baseline justify-between py-2 text-sm">
                        <span class="text-slate-700 dark:text-slate-300">
                            {{ $item['nome'] }}
                            <span class="text-slate-400">· {{ $item['vencimento'] }}</span>
                        </span>
                        <span class="figure text-slate-800 dark:text-slate-200">{{ Money::format($item['valor']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Composição, objetivos e proteção ---------------------------------- --}}
    <div class="grid gap-4 lg:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Composição das despesas</p>

            @if ((float) $m['despesas'] > 0)
                <div class="mt-4 flex h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                    @foreach (Necessity::cases() as $caso)
                        @php $pct = Money::percentageOf($m['composicao'][$caso->value], $m['despesas']); @endphp
                        @if ($pct > 0)
                            <div style="width: {{ $pct }}%; background: {{ $caso->color() }}"></div>
                        @endif
                    @endforeach
                </div>
                <ul class="mt-4 space-y-2">
                    @foreach (Necessity::cases() as $caso)
                        <li class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2 text-slate-600 dark:text-slate-400">
                                <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $caso->color() }}"></span>
                                {{ $caso->label() }}
                            </span>
                            <span class="figure text-slate-800 dark:text-slate-200">
                                {{ Money::format($m['composicao'][$caso->value]) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-4 text-sm text-slate-400">Nenhuma despesa neste mês.</p>
            @endif
        </div>

        <a href="{{ route('goals.index') }}" class="card card-hover block p-5">
            <p class="eyebrow">Objetivos</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">
                {{ Money::format($dados['objetivos']['acumulado']) }}
            </p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                de {{ Money::format($dados['objetivos']['meta']) }} em
                {{ $dados['objetivos']['quantidade'] }} {{ $dados['objetivos']['quantidade'] === 1 ? 'objetivo' : 'objetivos' }}
            </p>

            @if ($proximo = $dados['objetivos']['proximo'])
                <div class="mt-4 border-t border-slate-100 dark:border-white/10 pt-4">
                    <p class="text-xs text-slate-400">Prioridade 1</p>
                    <p class="mt-0.5 text-sm font-medium text-slate-800 dark:text-slate-200">{{ $proximo['name'] }}</p>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                        <div class="h-full rounded-full bg-brand-600 dark:bg-brand-400" style="width: {{ $proximo['progresso'] }}%"></div>
                    </div>
                </div>
            @endif
        </a>

        <a href="{{ route('insurance.index') }}" class="card card-hover block p-5">
            <p class="eyebrow">Proteção</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">
                {{ Money::compact($dados['protecao']['cobertura']) }}
            </p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                em cobertura ·
                {{ $dados['protecao']['quantidade'] }} {{ $dados['protecao']['quantidade'] === 1 ? 'apólice' : 'apólices' }}
            </p>
            <p class="mt-4 border-t border-slate-100 dark:border-white/10 pt-4 text-sm text-slate-700 dark:text-slate-300">
                <span class="figure">{{ Money::format($dados['protecao']['mensal']) }}</span><span class="text-slate-400">/mês</span>
            </p>
        </a>
    </div>

</div>
