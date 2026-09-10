@use('App\Support\Money')
@use('App\Models\InsurancePolicy')

@php
    $badgeColors = ['bg-brand-700', 'bg-accent-700', 'bg-brand-500', 'bg-accent-600', 'bg-brand-900'];
@endphp

<div class="space-y-8">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Seguros da carteira</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $totalGeral }} {{ $totalGeral === 1 ? 'apólice ativa' : 'apólices ativas' }} entre os clientes vinculados,
                separadas por tipo, cliente e seguradora.
            </p>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Seguradora</label>
            <select wire:model.live="seguradora" class="select mt-1.5">
                <option value="">Todas as seguradoras</option>
                @foreach ($seguradoras as $nome)
                    <option value="{{ $nome }}">{{ $nome }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($grouped->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                @if ($seguradora !== '')
                    Nenhuma apólice de {{ $seguradora }} entre os clientes vinculados.
                @else
                    Nenhuma apólice ativa entre os clientes vinculados ainda.
                @endif
            </p>
        </div>
    @else
        <div class="space-y-8">
            @foreach ($grouped as $grupoTipo)
                <section>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $grupoTipo['tipo']->label() }}</h2>

                    <div class="mt-3 space-y-6">
                        @foreach ($grupoTipo['clientes'] as $clienteNome => $porCliente)
                            <div class="card overflow-hidden">
                                <div class="border-b border-slate-100 bg-slate-50/60 px-5 py-2.5 dark:border-white/10 dark:bg-white/5">
                                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $clienteNome }}</p>
                                </div>

                                <div class="divide-y divide-slate-100 px-5 dark:divide-white/10">
                                    @foreach ($porCliente['membros'] as $porMembro)
                                        <div class="py-3">
                                            @if ($porCliente['separarPorMembro'])
                                                <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                                    {{ $porMembro['nome'] ?? 'Seguro familiar' }}
                                                </p>
                                            @endif

                                            <div @class(['space-y-3', 'mt-2' => $porCliente['separarPorMembro']])>
                                                @foreach ($porMembro['seguradoras'] as $seguradoraNome => $linhasDaSeguradora)
                                                    <div>
                                                        <div class="flex items-center gap-2">
                                                            <span @class([
                                                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-[10px] font-semibold text-white',
                                                                $badgeColors[InsurancePolicy::colorIndexFor($seguradoraNome, count($badgeColors))],
                                                            ])>
                                                                {{ InsurancePolicy::initialsFor($seguradoraNome) }}
                                                            </span>
                                                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ $seguradoraNome }}</span>
                                                        </div>

                                                        <div class="mt-2 overflow-x-auto">
                                                            <table class="w-full min-w-[640px] text-sm">
                                                                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                                                                    @foreach ($linhasDaSeguradora as $linha)
                                                                        @php $apolice = $linha['policy']; @endphp
                                                                        <tr>
                                                                            <td class="py-2 pr-3 text-slate-600 dark:text-slate-400">
                                                                                {{ $apolice->insured_item ?? ($apolice->policy_number ? "Apólice {$apolice->policy_number}" : '—') }}
                                                                            </td>
                                                                            <td class="py-2 pr-3 text-right tabular-nums text-slate-800 dark:text-slate-200">
                                                                                {{ $apolice->coverage_amount !== null ? Money::compact($apolice->coverage_amount) : '—' }}
                                                                            </td>
                                                                            <td class="py-2 pr-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                                                                                {{ Money::format($apolice->normalizedMonthlyCost()) }}/mês
                                                                            </td>
                                                                            <td class="py-2 pr-3">
                                                                                @if ($apolice->expiry_date === null)
                                                                                    <span class="text-xs text-slate-400">—</span>
                                                                                @elseif ($apolice->isExpiring(30))
                                                                                    <span class="badge bg-amber-50 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20">
                                                                                        {{ $apolice->expiry_date->format('d/m/Y') }}
                                                                                    </span>
                                                                                @else
                                                                                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ $apolice->expiry_date->format('d/m/Y') }}</span>
                                                                                @endif
                                                                            </td>
                                                                            <td class="py-2 text-right">
                                                                                <form method="POST" action="{{ route('profile.switch', $apolice->profile_id) }}">
                                                                                    @csrf
                                                                                    <button type="submit" class="btn-secondary px-3 py-1.5 whitespace-nowrap">Abrir perfil</button>
                                                                                </form>
                                                                            </td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @endif

</div>
