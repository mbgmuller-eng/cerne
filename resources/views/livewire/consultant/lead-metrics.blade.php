@use('App\Support\Money')

@php
    $seguros = $kind === \App\Enums\LeadKind::Insurance;
    $taxa = fn (?string $v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.').'%';
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Métricas dos leads</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Os contatos que entraram de {{ $de->format('d/m/Y') }} a {{ $ate->format('d/m/Y') }} e o que aconteceu com cada um.</p>
        </div>

        <div class="flex items-center gap-2">
            <select wire:model.live="periodo" class="select w-auto py-1.5 text-sm" aria-label="Período">
                @foreach ($periodos as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <a href="{{ route('consultant.leads', ['aba' => $aba]) }}" class="btn-ghost px-3 py-1.5 text-sm">Voltar aos leads</a>
        </div>
    </div>

    @unless ($ehCorretor)
        <div class="flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-white/5" role="tablist" aria-label="Funil">
            @foreach (['consultoria' => 'Consultoria', 'seguros' => 'Seguros'] as $chave => $rotulo)
                <button type="button" role="tab" wire:click="setAba('{{ $chave }}')" @class([
                    'flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition',
                    'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' => $aba === $chave,
                    'text-slate-500 dark:text-slate-400' => $aba !== $chave,
                ])>{{ $rotulo }}</button>
            @endforeach
        </div>
    @endunless

    {{-- O funil em números ------------------------------------------------------------- --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-4">
            <p class="eyebrow">Entraram</p>
            <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ $metricas['entered'] }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ $metricas['open'] }} ainda em andamento.</p>
        </div>
        <div class="card p-4">
            <p class="eyebrow">{{ $seguros ? 'Emitidos' : 'Convertidos' }}</p>
            <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ $metricas['issued'] }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ $metricas['lost'] }} {{ $metricas['lost'] === 1 ? 'perdido' : 'perdidos' }}.</p>
        </div>
        <div class="card p-4">
            <p class="eyebrow">Taxa de conversão</p>
            <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ $taxa($metricas['conversion']) }}</p>
            <p class="mt-1 text-xs text-slate-400">Dos contatos já decididos (fechados ou perdidos).</p>
        </div>
        <div class="card p-4">
            <p class="eyebrow">Tempo até fechar</p>
            <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">
                {{ $metricas['avg_days_to_issue'] === null ? '—' : number_format((float) $metricas['avg_days_to_issue'], 1, ',', '.') }}
                @if ($metricas['avg_days_to_issue'] !== null)<span class="text-xs font-normal text-slate-400"> dias</span>@endif
            </p>
            <p class="mt-1 text-xs text-slate-400">Em média, da entrada ao fechamento.</p>
        </div>
    </div>

    @if ($seguros)
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4">
                <p class="eyebrow">Prêmio emitido</p>
                <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ Money::format($metricas['premium']) }}<span class="text-xs font-normal text-slate-400"> por ano</span></p>
                <p class="mt-1 text-xs text-slate-400">Das cotações aceitas dos contatos que fecharam.
                    @if ($metricas['issued_without_quote'] > 0) {{ $metricas['issued_without_quote'] }} {{ $metricas['issued_without_quote'] === 1 ? 'fechou' : 'fecharam' }} sem cotação aceita e não {{ $metricas['issued_without_quote'] === 1 ? 'entra' : 'entram' }} na soma. @endif
                </p>
            </div>
            <div class="card p-4">
                <p class="eyebrow">Comissão (1º ano)</p>
                <p class="figure mt-1 text-xl font-medium text-slate-900 dark:text-white">{{ Money::format($metricas['commission']) }}</p>
                <p class="mt-1 text-xs text-slate-400">Só das cotações com comissão informada.</p>
            </div>
        </div>
    @endif

    {{-- De onde vêm ----------------------------------------------------------------------- --}}
    <div class="card p-4">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Por origem</h2>
        @if ($metricas['by_source'] === [])
            <p class="mt-2 text-xs text-slate-400">Nenhum contato entrou neste período.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[28rem] text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-500 dark:text-slate-400">
                            <th class="py-1.5 pr-3 font-medium">Origem</th>
                            <th class="px-3 py-1.5 text-right font-medium">Entraram</th>
                            <th class="px-3 py-1.5 text-right font-medium">{{ $seguros ? 'Emitidos' : 'Convertidos' }}</th>
                            <th class="px-3 py-1.5 text-right font-medium">Perdidos</th>
                            <th class="px-3 py-1.5 text-right font-medium">Em andamento</th>
                            <th class="py-1.5 pl-3 text-right font-medium">Conversão</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($metricas['by_source'] as $linha)
                            <tr>
                                <td class="py-2 pr-3 text-slate-800 dark:text-slate-200">{{ $linha['source']?->label() ?? 'Não informada' }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $linha['entered'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $linha['issued'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $linha['lost'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $linha['open'] }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums font-medium">{{ $taxa($linha['conversion']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Por que se perdem -------------------------------------------------------------------- --}}
    <div class="card p-4">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Por que se perdem</h2>
        @if ($metricas['lost_reasons'] === [])
            <p class="mt-2 text-xs text-slate-400">Nenhum contato perdido neste período.</p>
        @else
            <ul class="mt-3 space-y-2">
                @foreach ($metricas['lost_reasons'] as $motivo)
                    <li class="flex items-center gap-3 text-sm">
                        <span class="w-48 shrink-0 truncate text-slate-700 dark:text-slate-300">{{ $motivo['reason']?->label() ?? 'Sem categoria' }}</span>
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-white/10">
                            <span class="block h-full rounded-full bg-accent-500" style="width: {{ round($motivo['total'] * 100 / max(1, $metricas['lost'])) }}%"></span>
                        </span>
                        <span class="w-8 shrink-0 text-right tabular-nums text-slate-600 dark:text-slate-400">{{ $motivo['total'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <p class="text-xs text-slate-400">Períodos recentes parecem piores: parte dos contatos que entraram ainda não decidiu. A conversão considera só os decididos.</p>
</div>
