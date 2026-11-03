@use('App\Support\Money')
<div class="space-y-6">
    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Finanças da família</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $seguindo }} · só leitura. O que foi marcado como privado não aparece aqui.
        </p>
    </div>

    <nav class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" aria-label="Seções">
        @foreach ($abas as $chave => $rotulo)
            <button
                type="button"
                wire:click="abrir('{{ $chave }}')"
                @class([
                    'shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition',
                    'bg-brand-800 text-white dark:bg-brand-500' => $aba === $chave,
                    'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-white/5 dark:text-slate-300 dark:hover:bg-white/10' => $aba !== $chave,
                ])
                @if ($aba === $chave) aria-current="page" @endif
            >{{ $rotulo }}</button>
        @endforeach
    </nav>

    {{-- Resumo ------------------------------------------------------------ --}}
    @if ($aba === 'resumo')
        @php $p = $dados['painel']; @endphp
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-5">
                <p class="eyebrow">Investido</p>
                <p class="mt-1 font-display text-2xl font-semibold text-slate-900 dark:text-white">{{ Money::format($p['patrimonio']['investimentos']) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Em conta</p>
                <p class="mt-1 font-display text-2xl font-semibold text-slate-900 dark:text-white">{{ Money::format($p['patrimonio']['contas']) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Faturas em aberto</p>
                <p class="mt-1 font-display text-2xl font-semibold text-slate-900 dark:text-white">{{ Money::format($p['patrimonio']['faturas']) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Dívidas</p>
                <p class="mt-1 font-display text-2xl font-semibold text-slate-900 dark:text-white">{{ Money::format($p['dividas']['total']) }}</p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Este mês</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">Receitas</dt><dd class="tabular-nums text-slate-900 dark:text-white">{{ Money::format($p['mes']['receitas']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">Despesas</dt><dd class="tabular-nums text-slate-900 dark:text-white">{{ Money::format($p['mes']['despesas']) }}</dd></div>
                    <div class="flex justify-between border-t border-slate-100 pt-2 dark:border-white/10"><dt class="font-medium text-slate-700 dark:text-slate-200">Sobra</dt><dd class="tabular-nums font-medium text-slate-900 dark:text-white">{{ Money::format($p['mes']['sobra']) }}</dd></div>
                </dl>
            </section>

            <section class="card p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Contas a vencer</h2>
                    <button type="button" wire:click="abrir('vencimentos')" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Ver tudo</button>
                </div>
                @php $itens = collect($p['alertas']['itens'])->take(6); @endphp
                @if ($itens->isEmpty())
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nada vence nos próximos {{ $p['alertas']['dias'] }} dias.</p>
                @else
                    <ul class="mt-3 divide-y divide-slate-100 text-sm dark:divide-white/10">
                        @foreach ($itens as $item)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span class="min-w-0 truncate text-slate-700 dark:text-slate-200">{{ $item['nome'] }} <span class="text-slate-400">· {{ $item['vencimento'] }}</span></span>
                                <span class="shrink-0 tabular-nums text-slate-900 dark:text-white">{{ Money::format($item['valor']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Metas</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    {{ $p['objetivos']['quantidade'] }} {{ $p['objetivos']['quantidade'] === 1 ? 'meta ativa' : 'metas ativas' }}
                    @if ($p['objetivos']['proximo']) · a próxima é "{{ $p['objetivos']['proximo']['name'] }}" ({{ number_format($p['objetivos']['proximo']['progresso'], 0) }}%) @endif
                </p>
            </section>
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Seguros</h2>
                <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                    {{ $p['protecao']['quantidade'] }} {{ $p['protecao']['quantidade'] === 1 ? 'apólice ativa' : 'apólices ativas' }}
                    @if ($p['protecao']['quantidade'] > 0) · {{ Money::format($p['protecao']['mensal']) }} por mês @endif
                </p>
            </section>
        </div>

    {{-- Contas a vencer ---------------------------------------------------- --}}
    @elseif ($aba === 'vencimentos')
        @foreach (['atrasados' => 'Atrasadas', 'proximos' => 'Próximos 45 dias'] as $grupo => $titulo)
            <section class="card p-5">
                <h2 class="text-sm font-semibold {{ $grupo === 'atrasados' ? 'text-red-700 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">{{ $titulo }}</h2>
                @if ($dados[$grupo]->isEmpty())
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ $grupo === 'atrasados' ? 'Nenhuma conta atrasada.' : 'Nada vence nos próximos 45 dias.' }}</p>
                @else
                    <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($dados[$grupo] as $item)
                            <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                                <div class="min-w-0">
                                    <p class="truncate text-slate-800 dark:text-slate-200">{{ $item['nome'] }}</p>
                                    <p class="text-xs text-slate-400">{{ $item['tipo'] }} · vence em {{ $item['data']->format('d/m/Y') }}</p>
                                </div>
                                <span class="shrink-0 tabular-nums text-slate-900 dark:text-white">{{ Money::format($item['valor']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach

    {{-- Lançamentos --------------------------------------------------------- --}}
    @elseif ($aba === 'lancamentos')
        <div class="flex items-center justify-between">
            <button type="button" wire:click="mesAnterior" class="btn-secondary px-3 py-1.5 text-sm" aria-label="Mês anterior">←</button>
            <p class="text-sm font-medium capitalize text-slate-800 dark:text-slate-200">{{ $dados['rotuloMes'] }}</p>
            <button type="button" wire:click="mesSeguinte" @disabled($dados['ehMesAtual']) class="btn-secondary px-3 py-1.5 text-sm disabled:opacity-40" aria-label="Mês seguinte">→</button>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="card p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Despesas</h2>
                    <span class="text-sm tabular-nums font-medium text-slate-900 dark:text-white">{{ Money::format($dados['totalDespesas']) }}</span>
                </div>
                @forelse ($dados['despesas'] as $d)
                    @if ($loop->first)<ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">@endif
                        <li class="flex items-center justify-between gap-3 py-2 text-sm">
                            <div class="min-w-0">
                                <p class="truncate text-slate-800 dark:text-slate-200">{{ $d->description }}</p>
                                <p class="text-xs text-slate-400">{{ $d->expense_date->format('d/m') }}@if ($d->category) · {{ $d->category->name }}@endif</p>
                            </div>
                            <span class="shrink-0 tabular-nums text-slate-900 dark:text-white">{{ Money::format($d->amount) }}</span>
                        </li>
                    @if ($loop->last)</ul>@endif
                @empty
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhuma despesa neste mês.</p>
                @endforelse
            </section>

            <section class="card p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Receitas</h2>
                    <span class="text-sm tabular-nums font-medium text-slate-900 dark:text-white">{{ Money::format($dados['totalReceitas']) }}</span>
                </div>
                @forelse ($dados['receitas'] as $r)
                    @if ($loop->first)<ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">@endif
                        <li class="flex items-center justify-between gap-3 py-2 text-sm">
                            <div class="min-w-0">
                                <p class="truncate text-slate-800 dark:text-slate-200">{{ $r->description }}</p>
                                <p class="text-xs text-slate-400">{{ $r->received_date->format('d/m') }}@if ($r->category) · {{ $r->category->name }}@endif</p>
                            </div>
                            <span class="shrink-0 tabular-nums text-slate-900 dark:text-white">{{ Money::format($r->amount) }}</span>
                        </li>
                    @if ($loop->last)</ul>@endif
                @empty
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhuma receita neste mês.</p>
                @endforelse
            </section>
        </div>

    {{-- Contas e cartões ------------------------------------------------------ --}}
    @elseif ($aba === 'contas')
        <section class="card p-5">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Contas</h2>
                <span class="text-sm tabular-nums font-medium text-slate-900 dark:text-white">{{ Money::format($dados['saldoTotal']) }}</span>
            </div>
            @forelse ($dados['contas'] as $conta)
                @if ($loop->first)<ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">@endif
                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate text-slate-800 dark:text-slate-200">{{ $conta->bank_name }}</p>
                            <p class="text-xs text-slate-400">{{ $conta->account_type->label() }}@if ($conta->is_joint) · conjunta @endif</p>
                        </div>
                        <span class="shrink-0 tabular-nums text-slate-900 dark:text-white">{{ Money::format($conta->current_balance) }}</span>
                    </li>
                @if ($loop->last)</ul>@endif
            @empty
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhuma conta para mostrar.</p>
            @endforelse
        </section>

        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Cartões de crédito</h2>
            @forelse ($dados['cartoes'] as $cartao)
                @if ($loop->first)<ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">@endif
                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate text-slate-800 dark:text-slate-200">{{ $cartao->card_name }}</p>
                            <p class="text-xs text-slate-400">{{ $cartao->bank_name }} · limite {{ Money::format($cartao->credit_limit) }}</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="tabular-nums text-slate-900 dark:text-white">{{ Money::format($dados['faturas'][$cartao->id] ?? 0) }}</p>
                            <p class="text-xs text-slate-400">fatura em aberto</p>
                        </div>
                    </li>
                @if ($loop->last)</ul>@endif
            @empty
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhum cartão para mostrar.</p>
            @endforelse
        </section>

    {{-- Investimentos --------------------------------------------------------- --}}
    @elseif ($aba === 'investimentos')
        <div class="card p-5">
            <p class="eyebrow">Total investido</p>
            <p class="mt-1 font-display text-2xl font-semibold text-slate-900 dark:text-white">{{ Money::format($dados['total']) }}</p>
        </div>
        @forelse ($dados['grupos'] as $grupo => $ativos)
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $grupo }}</h2>
                <ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($ativos as $ativo)
                        <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                            <div class="min-w-0">
                                <p class="truncate text-slate-800 dark:text-slate-200">{{ $ativo->displayName() }}</p>
                                @if ($ativo->institution)<p class="text-xs text-slate-400">{{ $ativo->institution }}</p>@endif
                            </div>
                            <span class="shrink-0 tabular-nums text-slate-900 dark:text-white">{{ Money::format($ativo->current_amount) }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="text-sm text-slate-500 dark:text-slate-400">Nenhum investimento para mostrar.</p>
        @endforelse

    {{-- Dívidas ---------------------------------------------------------------- --}}
    @elseif ($aba === 'dividas')
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Financiamentos, empréstimos e consórcios</h2>
            @forelse ($dados['dividas'] as $divida)
                @if ($loop->first)<ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">@endif
                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate text-slate-800 dark:text-slate-200">{{ $divida->name }}</p>
                            <p class="text-xs text-slate-400">{{ $divida->kind->label() }}@if ($divida->creditor) · {{ $divida->creditor }}@endif</p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="tabular-nums text-slate-900 dark:text-white">{{ Money::format($divida->balance()) }}</p>
                            <p class="text-xs text-slate-400">{{ $divida->balanceLabel() }}</p>
                        </div>
                    </li>
                @if ($loop->last)</ul>@endif
            @empty
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhuma dívida para mostrar.</p>
            @endforelse
        </section>

    {{-- Seguros ---------------------------------------------------------------- --}}
    @elseif ($aba === 'seguros')
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Apólices ativas</h2>
            @forelse ($dados['apolices'] as $apolice)
                @if ($loop->first)<ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">@endif
                    <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <div class="min-w-0">
                            <p class="truncate text-slate-800 dark:text-slate-200">{{ $apolice->insurance_type->label() }} · {{ $apolice->insurer_name }}</p>
                            <p class="text-xs text-slate-400">
                                @if ($apolice->insured_person_name){{ $apolice->insured_person_name }} · @endif
                                @if ($apolice->expiry_date)vigência até {{ $apolice->expiry_date->format('d/m/Y') }}@endif
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            @if ($apolice->coverage_amount)<p class="tabular-nums text-slate-900 dark:text-white">{{ Money::format($apolice->coverage_amount) }}</p><p class="text-xs text-slate-400">cobertura</p>@endif
                        </div>
                    </li>
                @if ($loop->last)</ul>@endif
            @empty
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhuma apólice para mostrar.</p>
            @endforelse
        </section>

    {{-- Metas ------------------------------------------------------------------ --}}
    @elseif ($aba === 'metas')
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Metas ativas</h2>
            @forelse ($dados['metas'] as $meta)
                @if ($loop->first)<ul class="mt-3 divide-y divide-slate-100 dark:divide-white/10">@endif
                    <li class="py-3 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="min-w-0 truncate text-slate-800 dark:text-slate-200">{{ $meta->name }}</p>
                            <span class="shrink-0 tabular-nums text-xs text-slate-500 dark:text-slate-400">{{ Money::format($meta->accumulated()) }} de {{ Money::format($meta->estimated_value) }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                            <div class="h-full rounded-full bg-emerald-600" style="width: {{ min(100, $meta->progressPercentage()) }}%"></div>
                        </div>
                    </li>
                @if ($loop->last)</ul>@endif
            @empty
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhuma meta para mostrar.</p>
            @endforelse
        </section>
    @endif
</div>
