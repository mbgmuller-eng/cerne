@use('App\Support\Money')
@use('App\Enums\Necessity')
@use('App\Support\CurrencyBreakdown')

@php
    $p = $dados['patrimonio'];
    // Só há o que filtrar quando existe investimento em USDT, BTC ou ETH.
    $linhasMoeda = $p['por_moeda'] ?? [];
    $temMoeda = CurrencyBreakdown::hasForeign($linhasMoeda);
    $porMoeda = $temMoeda && $exibicao === 'moedas';
    $m = $dados['mes'];
    // O cache guarda array puro (ver DashboardService::overview); a
    // Collection é reconstituída aqui, fora do (un)serialize do cache.
    $evolucao = collect($dados['evolucao']);
    $alertas = $dados['alertas'];
    $alertas['itens'] = collect($alertas['itens']);
    $maximo = (float) max($evolucao->max('receitas'), $evolucao->max('despesas'), 1);
    $variacao = $dados['variacao'];
    $contas = $dados['contas'];
    $semDinheiroNaConta = count($contas['linhas']) === 0;
    $vencidas = (int) $dados['vencidas'];
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
        <x-seletor-mes :rotulo="$referencia->translatedFormat('F \d\e Y')" :hoje="$ehMesAtual ? null : 'currentMonth'" class="order-last w-full lg:order-none lg:w-auto" />
    </div>

    {{-- Cartão de alerta: o que pede atenção primeiro (vencido e a vencer) ------------ --}}
    @if ($vencidas > 0 || $alertas['itens']->isNotEmpty())
        <div @class(['card border-l-4 p-5', 'border-l-red-500' => $vencidas > 0, 'border-l-amber-500' => $vencidas === 0]) data-alerta>
            @if ($vencidas > 0)
                <a href="{{ route('fixedbills.index') }}" class="flex items-center justify-between gap-3 text-sm font-medium text-red-700 dark:text-red-300">
                    <span>{{ $vencidas }} {{ $vencidas === 1 ? 'conta fixa vencida' : 'contas fixas vencidas' }}, sem pagamento</span>
                    <span class="text-xs underline">Ver contas</span>
                </a>
            @endif
            @if ($alertas['itens']->isNotEmpty())
                <div @class(['flex items-baseline justify-between', 'mt-3 border-t border-slate-100 pt-3 dark:border-white/10' => $vencidas > 0])>
                    <p class="text-sm font-medium text-slate-900 dark:text-white">Vencendo nos próximos {{ $alertas['dias'] }} dias</p>
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
            @endif
        </div>
    @endif

    {{-- Patrimônio líquido — o número-manchete --------------------------- --}}
    <div class="card relative overflow-hidden bg-brand-800 p-6 text-white sm:p-8">
        <div class="pointer-events-none absolute -top-24 -right-24 h-64 w-64 rounded-full bg-brand-600/50 dark:bg-brand-400/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-32 -left-16 h-64 w-64 rounded-full bg-brand-950/70 blur-3xl"></div>

        <div class="relative">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="eyebrow text-brand-200">Patrimônio investido</p>

                @if ($temMoeda)
                    <div class="inline-flex rounded-lg bg-white/10 p-0.5 text-xs" role="group" aria-label="Mostrar patrimônio">
                        <button type="button" wire:click="exibir('reais')" class="rounded-md px-3 py-1 {{ ! $porMoeda ? 'bg-white text-brand-800' : 'text-brand-100' }}">Em reais</button>
                        <button type="button" wire:click="exibir('moedas')" class="rounded-md px-3 py-1 {{ $porMoeda ? 'bg-white text-brand-800' : 'text-brand-100' }}">Por moeda</button>
                    </div>
                @endif
            </div>

            @if ($porMoeda)
                <x-currency-breakdown :rows="$linhasMoeda" class="mt-4" />
                <p class="mt-3 text-xs text-brand-200">Tudo em reais, líquido das faturas em aberto: {{ Money::format($p['liquido']) }}</p>
            @else
                <p class="figure mt-2 text-4xl font-medium sm:text-5xl {{ (float) $p['liquido'] < 0 ? 'text-red-200' : '' }}">
                    {{ Money::format($p['liquido']) }}
                </p>
            @endif

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

    {{-- Visão geral do mês: quatro cartões, 2x2 no celular, com a variação contra o mês anterior ----- --}}
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <div class="card p-4 sm:p-5" data-kpi="receitas">
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg></span>
                <p class="text-sm text-slate-500 dark:text-slate-400">Receitas</p>
            </div>
            <p class="figure mt-2 text-lg font-medium sm:text-xl text-slate-900 dark:text-white">{{ Money::format($m['receitas']) }}</p>
            <x-variacao :dado="$variacao['receitas']" class="mt-3" />
        </div>
        <div class="card p-4 sm:p-5" data-kpi="despesas">
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 12h14"/></svg></span>
                <p class="text-sm text-slate-500 dark:text-slate-400">Despesas</p>
            </div>
            <p class="figure mt-2 text-lg font-medium sm:text-xl text-slate-900 dark:text-white">{{ Money::format($m['despesas']) }}</p>
            <x-variacao :dado="$variacao['despesas']" class="mt-3" />
        </div>
        <div class="card p-4 sm:p-5" data-kpi="sobra">
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h10M7 12h10M7 17h6"/></svg></span>
                <p class="text-sm text-slate-500 dark:text-slate-400">Sobra</p>
            </div>
            <p class="figure mt-2 text-lg font-medium sm:text-xl {{ (float) $m['sobra'] < 0 ? 'text-red-700 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ Money::format($m['sobra']) }}</p>
            <x-variacao :dado="$variacao['sobra']" class="mt-3" />
            {{-- Sem receita no mês não há o que poupar: "poupança de 0,0%" seria um número sem sentido. --}}
            @if (bccomp($m['receitas'], '0', 2) > 0)
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">poupança de {{ number_format($m['taxa_poupanca'], 1, ',', '.') }}%</p>
            @endif
        </div>
        <a href="{{ route('accounts.index') }}" class="card card-hover p-4 sm:p-5" data-kpi="faturas">
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300" aria-hidden="true"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><rect x="2" y="6" width="20" height="13" rx="2"/><path d="M2 10h20"/></svg></span>
                <p class="text-sm text-slate-500 dark:text-slate-400">Faturas a pagar</p>
            </div>
            <p class="figure mt-2 text-lg font-medium sm:text-xl text-slate-900 dark:text-white">{{ Money::format($p['faturas']) }}</p>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">em aberto nos cartões</p>
        </a>
    </div>

    {{-- Linha do saldo: onde o mês começou, onde está e para onde vai (só o mês de hoje, porque o saldo é o de hoje) ---- --}}
    @if (! $semDinheiroNaConta && $contas['mes_atual'])
        <div class="card p-5 sm:p-6" data-linha-saldo>
            <p class="eyebrow">Saldo em contas</p>
            <div class="relative mt-5 grid grid-cols-3 gap-2 text-center">
                <div class="absolute top-[0.6rem] right-[16.6%] left-[16.6%] h-px bg-slate-200 dark:bg-white/10" aria-hidden="true"></div>
                @foreach ([['Início do mês', $contas['totais']['inicial'], 'bg-slate-400'], ['Hoje', $contas['totais']['saldo'], 'bg-brand-600 dark:bg-brand-400'], ['Previsto', $contas['totais']['previsto'], 'bg-amber-500']] as [$rotulo, $valor, $ponto])
                    <div class="relative">
                        <span class="mx-auto block h-3 w-3 rounded-full ring-4 ring-white dark:ring-slate-900 {{ $ponto }}"></span>
                        <p class="figure mt-2 text-sm font-medium sm:text-lg {{ (float) $valor < 0 ? 'text-red-700 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ Money::format($valor) }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $rotulo }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 text-xs text-slate-400">O previsto soma o que ainda vai entrar e tira as contas fixas do mês que faltam pagar. É uma estimativa.</p>
        </div>
    @endif

    {{-- Regra de orçamento do mês (meta x lançado por área) ---------------- --}}
    <livewire:budget.rule-summary />

    {{-- Evolução ---------------------------------------------------------- --}}
    <div class="card p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="eyebrow">Receitas e despesas · últimos {{ $evolucao->count() }} meses</p>
            <div class="flex gap-4 text-xs text-slate-500 dark:text-slate-400">
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-brand-600 dark:bg-brand-400"></span>receitas</span>
                <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500"></span>despesas</span>
            </div>
        </div>

        <div class="mt-6">
            <x-bar-chart
                :meses="$evolucao->pluck('rotulo')->all()"
                :series="[
                    ['cor' => 'currentColor', 'classe' => 'text-brand-600 dark:text-brand-400', 'valores' => $evolucao->pluck('receitas')->map(fn ($v) => (float) $v)->all(), 'rotulo' => 'Receitas'],
                    ['cor' => 'currentColor', 'classe' => 'text-amber-500', 'valores' => $evolucao->pluck('despesas')->map(fn ($v) => (float) $v)->all(), 'rotulo' => 'Despesas'],
                ]"
                :maximo="$maximo"
                :height="180"
                :width="720"
                modo="agrupado"
            />
        </div>
    </div>

    {{-- Ranking de categorias e últimas despesas ------------------------------------ --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <div class="card p-5 sm:p-6" data-ranking>
            <p class="eyebrow">Para onde foi o dinheiro</p>
            @forelse ($dados['ranking'] as $linha)
                <div class="mt-4 flex items-center gap-3">
                    <x-icone-categoria :nome="$linha['nome']" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $linha['nome'] }}</p>
                            <p class="figure shrink-0 text-sm text-slate-700 dark:text-slate-300">{{ Money::format($linha['valor']) }}</p>
                        </div>
                        <div class="mt-1.5 flex items-center gap-2">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700"><div class="h-full rounded-full bg-brand-600 dark:bg-brand-400" style="width: {{ min(100, $linha['pct']) }}%"></div></div>
                            <span class="w-10 shrink-0 text-right text-xs text-slate-500 dark:text-slate-400">{{ number_format($linha['pct'], 1, ',', '.') }}%</span>
                        </div>
                    </div>
                </div>
            @empty
                <p class="mt-4 text-sm text-slate-400">Nenhuma despesa neste mês.</p>
            @endforelse
        </div>

        <div class="card p-5 sm:p-6" data-ultimas>
            <div class="flex items-baseline justify-between">
                <p class="eyebrow">Últimas despesas</p>
                <a href="{{ route('cashflow.index', ['year' => $referencia->year, 'month' => $referencia->month]) }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Ver todas</a>
            </div>
            @forelse ($dados['recentes'] as $d)
                <div class="mt-4 flex items-center gap-3">
                    <x-icone-categoria :nome="$d['categoria']" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $d['descricao'] }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $d['categoria'] }} · {{ $d['dia'] }}</p>
                    </div>
                    <p class="figure shrink-0 text-sm text-slate-700 dark:text-slate-300">{{ Money::format($d['valor']) }}</p>
                </div>
            @empty
                <p class="mt-4 text-sm text-slate-400">Nenhuma despesa neste mês.</p>
            @endforelse
        </div>
    </div>

    {{-- Contas: o que entrou, o que saiu, o saldo e o previsto de cada uma -------------- --}}
    @if (! $semDinheiroNaConta)
        <div class="card p-5 sm:p-6" data-contas>
            <div class="flex items-baseline justify-between">
                <p class="eyebrow">Contas</p>
                <a href="{{ route('accounts.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Gerenciar</a>
            </div>

            {{-- Celular: uma linha por conta, empilhada (nada passa da largura da tela) --}}
            <ul class="mt-3 divide-y divide-slate-100 sm:hidden dark:divide-white/10">
                @foreach ($contas['linhas'] as $c)
                    <li class="py-3">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $c['nome'] }}</p>
                            <p class="figure shrink-0 text-sm text-slate-900 dark:text-white">{{ Money::format($c['saldo']) }}</p>
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Entrou {{ Money::format($c['receitas']) }} · Saiu {{ Money::format($c['despesas']) }} · Previsto {{ Money::format($c['previsto']) }}</p>
                    </li>
                @endforeach
                <li class="flex items-baseline justify-between gap-2 py-3 text-sm font-medium">
                    <span class="text-slate-700 dark:text-slate-300">Total</span>
                    <span class="figure text-slate-900 dark:text-white">{{ Money::format($contas['totais']['saldo']) }}</span>
                </li>
            </ul>

            {{-- Tela maior: tabela --}}
            <div class="mt-3 hidden overflow-x-auto sm:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500 dark:text-slate-400">
                            <th class="pb-2 font-medium">Conta</th>
                            <th class="pb-2 text-right font-medium">Receitas</th>
                            <th class="pb-2 text-right font-medium">Despesas</th>
                            <th class="pb-2 text-right font-medium">Saldo</th>
                            <th class="pb-2 text-right font-medium">Previsto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($contas['linhas'] as $c)
                            <tr>
                                <td class="py-2.5 pr-3 text-slate-800 dark:text-slate-200">{{ $c['nome'] }}</td>
                                <td class="figure py-2.5 text-right text-slate-700 dark:text-slate-300">{{ Money::format($c['receitas']) }}</td>
                                <td class="figure py-2.5 text-right text-slate-700 dark:text-slate-300">{{ Money::format($c['despesas']) }}</td>
                                <td class="figure py-2.5 text-right font-medium text-slate-900 dark:text-white">{{ Money::format($c['saldo']) }}</td>
                                <td class="figure py-2.5 text-right text-slate-500 dark:text-slate-400">{{ Money::format($c['previsto']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-slate-200 font-medium dark:border-white/10">
                            <td class="pt-2.5 text-slate-700 dark:text-slate-300">Total</td>
                            <td class="figure pt-2.5 text-right">{{ Money::format($contas['totais']['receitas']) }}</td>
                            <td class="figure pt-2.5 text-right">{{ Money::format($contas['totais']['despesas']) }}</td>
                            <td class="figure pt-2.5 text-right text-slate-900 dark:text-white">{{ Money::format($contas['totais']['saldo']) }}</td>
                            <td class="figure pt-2.5 text-right text-slate-500 dark:text-slate-400">{{ Money::format($contas['totais']['previsto']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif

    {{-- Dívidas: financiamentos e empréstimos ------------------------------ --}}
    @if (bccomp($dados['dividas']['total'], '0', 2) > 0 || bccomp($dados['dividas']['month_total'], '0', 2) > 0)
        <a href="{{ route('debts.index') }}" class="card card-hover flex flex-wrap items-center justify-between gap-x-8 gap-y-3 p-5">
            <div>
                <p class="eyebrow">Dívidas</p>
                <p class="figure mt-1 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($dados['dividas']['total']) }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">total devedor em financiamentos e empréstimos</p>
            </div>
            <div class="text-right">
                <p class="text-xs text-slate-500 dark:text-slate-400">Parcelas deste mês</p>
                <p class="figure mt-1 text-lg text-amber-700 dark:text-amber-400">{{ Money::format($dados['dividas']['month_total']) }}</p>
                <p class="mt-0.5 text-xs text-slate-400">{{ Money::format($dados['dividas']['month_open']) }} a pagar</p>
            </div>
        </a>
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
