@use('App\Support\Money')
@use('App\Models\InsurancePolicy')

@php
    $badgeColors = ['bg-brand-700', 'bg-accent-700', 'bg-brand-500', 'bg-accent-600', 'bg-brand-900'];
@endphp

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Seguros da carteira</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $totalGeral }} {{ $totalGeral === 1 ? 'apólice ativa' : 'apólices ativas' }} entre os clientes vinculados.
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

    @if ($linhas->isEmpty())
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
        <div class="card overflow-x-auto">
            <table class="w-full min-w-[900px] text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-left text-[11px] tracking-wide text-slate-400 uppercase dark:border-white/10">
                        <th class="px-5 py-3 font-semibold">Cliente</th>
                        <th class="px-3 py-3 font-semibold">Seguradora</th>
                        <th class="px-3 py-3 font-semibold">Tipo</th>
                        <th class="px-3 py-3 text-right font-semibold">Cobertura</th>
                        <th class="px-3 py-3 text-right font-semibold">Mensal</th>
                        <th class="px-3 py-3 font-semibold">Vigência</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($linhas as $linha)
                        @php $apolice = $linha['policy']; @endphp
                        <tr>
                            <td class="max-w-40 px-5 py-3">
                                <p class="truncate font-medium text-slate-800 dark:text-slate-200">{{ $linha['client_name'] }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <span @class([
                                        'flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-[10px] font-semibold text-white',
                                        $badgeColors[InsurancePolicy::colorIndexFor($apolice->insurer_name, count($badgeColors))],
                                    ])>
                                        {{ InsurancePolicy::initialsFor($apolice->insurer_name) }}
                                    </span>
                                    <span class="text-slate-700 dark:text-slate-300">{{ $apolice->insurer_name }}</span>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-slate-600 dark:text-slate-400">{{ $apolice->insurance_type->label() }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-800 dark:text-slate-200">
                                {{ $apolice->coverage_amount !== null ? Money::compact($apolice->coverage_amount) : '—' }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">
                                {{ Money::format($apolice->normalizedMonthlyCost()) }}
                            </td>
                            <td class="px-3 py-3">
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
                            <td class="px-5 py-3 text-right">
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
    @endif

</div>
