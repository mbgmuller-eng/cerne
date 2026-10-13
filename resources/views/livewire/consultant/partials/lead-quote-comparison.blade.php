@use('App\Support\Money')
@use('App\Support\LeadQuoteComparison')

{{--
    Cotações do mesmo tipo lado a lado: prêmio, capital, preço por R$ 1.000 de capital, validade e cada proteção.
    Recebe $groups (LeadQuoteComparison::build). O melhor valor de cada linha fica em destaque; é guia, não veredito.
--}}
@php
    $ok = 'font-semibold text-emerald-700 dark:text-emerald-400';
    $rotulo = 'sticky left-0 bg-white py-2 pr-3 text-left text-xs font-medium text-slate-500 dark:bg-slate-900 dark:text-slate-400';
@endphp

<div class="space-y-4">
    @foreach ($groups as $grupo)
        <div class="space-y-2" wire:key="comparacao-{{ $grupo['type'] }}">
            <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ \App\Enums\InsuranceType::from($grupo['type'])->label() }} · {{ count($grupo['quotes']) }} cotações</p>

            <div class="overflow-x-auto rounded-xl border border-slate-100 dark:border-white/10">
                <table class="w-full min-w-[32rem] border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-white/10">
                            <th class="{{ $rotulo }} px-3"></th>
                            @foreach ($grupo['quotes'] as $q)
                                <th class="min-w-[9rem] px-3 py-2 text-left align-bottom" wire:key="cab-{{ $q->id }}">
                                    <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $q->insurer_name }}</span>
                                    @if ($q->product)
                                        <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">{{ $q->product }}</span>
                                    @endif
                                    <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[10px] font-medium text-white" style="background: {{ $q->status->color() }}">{{ $q->status->label() }}</span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                        <tr>
                            <th class="{{ $rotulo }} px-3">Prêmio por ano</th>
                            @foreach ($grupo['quotes'] as $q)
                                <td class="px-3 py-2 tabular-nums {{ LeadQuoteComparison::isBest($q->annualPremium(), $grupo['best']['premium']) ? $ok : 'text-slate-700 dark:text-slate-300' }}">
                                    {{ $q->annualPremium() !== null ? Money::format($q->annualPremium()) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th class="{{ $rotulo }} px-3">Equivale a, por mês</th>
                            @foreach ($grupo['quotes'] as $q)
                                <td class="px-3 py-2 tabular-nums text-slate-700 dark:text-slate-300">{{ $q->monthlyPremium() !== null ? Money::format($q->monthlyPremium()) : '—' }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th class="{{ $rotulo }} px-3">{{ $grupo['type'] === 'vida' ? 'Capital por morte' : 'Capital' }}</th>
                            @foreach ($grupo['quotes'] as $q)
                                <td class="px-3 py-2 tabular-nums {{ LeadQuoteComparison::isBest($q->coverage_amount === null ? null : (string) $q->coverage_amount, $grupo['best']['capital']) ? $ok : 'text-slate-700 dark:text-slate-300' }}">
                                    {{ $q->coverage_amount !== null ? Money::format($q->coverage_amount) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th class="{{ $rotulo }} px-3">Prêmio anual por R$ 1.000 de capital</th>
                            @foreach ($grupo['quotes'] as $q)
                                <td class="px-3 py-2 tabular-nums {{ LeadQuoteComparison::isBest($grupo['costPerThousand'][$q->id], $grupo['best']['cost']) ? $ok : 'text-slate-700 dark:text-slate-300' }}">
                                    {{ $grupo['costPerThousand'][$q->id] !== null ? Money::format($grupo['costPerThousand'][$q->id]) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                        <tr>
                            <th class="{{ $rotulo }} px-3">Válida até</th>
                            @foreach ($grupo['quotes'] as $q)
                                <td class="px-3 py-2 text-slate-700 dark:text-slate-300">{{ $q->valid_until?->format('d/m/Y') ?? '—' }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th class="{{ $rotulo }} px-3">Comissão prevista (1º ano)</th>
                            @foreach ($grupo['quotes'] as $q)
                                <td class="px-3 py-2 tabular-nums text-slate-700 dark:text-slate-300">{{ $q->expectedCommission() !== null ? Money::format($q->expectedCommission()) : '—' }}</td>
                            @endforeach
                        </tr>

                        @if ($grupo['coverages'] !== [])
                            <tr class="bg-slate-50 dark:bg-slate-800/50">
                                <th colspan="{{ count($grupo['quotes']) + 1 }}" class="px-3 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Proteções</th>
                            </tr>
                            @foreach ($grupo['coverages'] as $linha)
                                <tr>
                                    <th class="{{ $rotulo }} px-3 font-normal">{{ $linha['name'] }}</th>
                                    @foreach ($grupo['quotes'] as $q)
                                        @php $celula = $linha['cells'][$q->id]; @endphp
                                        <td class="px-3 py-2 tabular-nums {{ $celula === null ? 'text-slate-300 dark:text-slate-600' : (LeadQuoteComparison::isBest($celula['value'], $linha['best']) ? $ok : 'text-slate-700 dark:text-slate-300') }}">
                                            @if ($celula === null)
                                                não inclui
                                            @else
                                                {{ $celula['value'] !== null ? Money::format($celula['value']) : 'inclui' }}
                                                @if ($celula['note']) <span class="block text-[11px] font-normal text-slate-400">{{ $celula['note'] }}</span> @endif
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <p class="text-xs text-slate-400">Compara só cotações do mesmo tipo de seguro que ainda estão na mesa (não recusadas nem vencidas). Em verde, o melhor valor de cada linha: menor prêmio, maior capital ou limite. "Não inclui" quer dizer que a cotação não traz aquela proteção.</p>
</div>
