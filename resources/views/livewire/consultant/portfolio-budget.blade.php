@use('App\Support\Money')
@use('Carbon\CarbonImmutable')

@php
    $mesTexto = CarbonImmutable::create($ano, $mes, 1)->locale('pt_BR')->translatedFormat('F \d\e Y');
    $cor = [
        'reached' => 'text-emerald-700 dark:text-emerald-400',
        'ok' => 'text-emerald-700 dark:text-emerald-400',
        'partial' => 'text-amber-700 dark:text-amber-400',
        'none' => 'text-slate-500 dark:text-slate-400',
        'over' => 'text-red-700 dark:text-red-400',
    ];
    $totais = $visao['totals'];
@endphp

<div class="space-y-6" data-portfolio-budget>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Regra da carteira</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Como cada cliente está em relação à regra de orçamento dele.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1 rounded-lg ring-1 ring-slate-200 dark:ring-white/10">
                <button type="button" wire:click="previousMonth" class="btn-ghost px-2.5 py-1.5 text-sm" aria-label="Mês anterior">‹</button>
                <span class="min-w-[9rem] px-1 text-center text-sm font-medium first-letter:uppercase text-slate-800 dark:text-slate-200">{{ $mesTexto }}</span>
                <button type="button" wire:click="nextMonth" class="btn-ghost px-2.5 py-1.5 text-sm" aria-label="Próximo mês">›</button>
            </div>
            @unless ($ehMesAtual)
                <button type="button" wire:click="currentMonth" class="btn-ghost px-2 py-1.5 text-xs">Mês atual</button>
            @endunless
            <a href="{{ route('consultant.budget-templates') }}" class="btn-secondary px-3 py-1.5 text-sm">Modelos de regra</a>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-4" data-budget-totals>
        <div class="card p-4"><p class="eyebrow">Clientes ativos</p><p class="figure mt-1 text-2xl font-medium text-slate-900 dark:text-white">{{ $totais['clients'] }}</p></div>
        <div class="card p-4"><p class="eyebrow">Com regra</p><p class="figure mt-1 text-2xl font-medium text-slate-900 dark:text-white">{{ $totais['with_rule'] }}</p></div>
        <div class="card p-4"><p class="eyebrow">Dentro dos limites</p><p class="figure mt-1 text-2xl font-medium text-slate-900 dark:text-white">{{ $totais['limits_ok'] }}</p><p class="text-xs text-slate-500 dark:text-slate-400">essencial e supérfluo</p></div>
        <div class="card p-4"><p class="eyebrow">Meta de investimento batida</p><p class="figure mt-1 text-2xl font-medium text-slate-900 dark:text-white">{{ $totais['investment_reached'] }}</p></div>
    </div>

    @if ($visao['rows'] === [])
        <div class="card p-6 text-sm text-slate-500 dark:text-slate-400">Você não tem clientes com vínculo ativo.</div>
    @else
        <div class="card overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
                        <th class="px-4 py-3 font-medium">Cliente</th>
                        <th class="px-4 py-3 font-medium">Receita do mês</th>
                        <th class="px-4 py-3 font-medium">Essencial</th>
                        <th class="px-4 py-3 font-medium">Supérfluo</th>
                        <th class="px-4 py-3 font-medium">Investimentos</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($visao['rows'] as $linha)
                        <tr data-client-row="{{ $linha['profile_id'] }}" wire:key="reg-{{ $linha['profile_id'] }}">
                            <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">{{ $linha['name'] }}</td>
                            @if ($linha['error'])
                                <td colspan="4" class="px-4 py-3 text-xs text-red-700 dark:text-red-400">Não foi possível calcular este cliente agora.</td>
                            @elseif (! $linha['has_rule'])
                                <td colspan="4" class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400" data-no-rule>Sem regra de orçamento.</td>
                            @else
                                <td class="px-4 py-3 tabular-nums text-slate-700 dark:text-slate-300">{{ Money::format($linha['income']) }}</td>
                                @foreach (['essential', 'discretionary', 'investment'] as $area)
                                    @php $a = $linha['areas'][$area]; @endphp
                                    <td class="px-4 py-3 text-xs tabular-nums {{ $cor[$a['status']] }}" data-area="{{ $area }}" data-status="{{ $a['status'] }}">
                                        {{ Money::format($a['realized']) }} <span class="text-slate-400">de {{ Money::format($a['target']) }}</span>
                                        <span class="block text-[11px]">
                                            @if ($a['direction'] === 'max')
                                                {{ $a['status'] === 'over' ? 'passou '.Money::format($a['over']) : 'dentro do limite' }}
                                            @else
                                                {{ bccomp($a['missing'], '0', 2) > 0 ? 'faltam '.Money::format($a['missing']) : 'meta batida' }}
                                            @endif
                                        </span>
                                    </td>
                                @endforeach
                            @endif
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('profile.switch', $linha['profile_id']) }}">
                                    @csrf
                                    <button type="submit" class="btn-secondary whitespace-nowrap px-3 py-1.5 text-xs">Abrir perfil</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">Os números enxergam tudo do cliente, inclusive o que é privado entre o casal. A receita do mês é o que entrou mais o previsto nas receitas recorrentes.</p>
    @endif
</div>
