@use('App\Support\Money')

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Investimentos da carteira</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $totalGeral }} {{ $totalGeral === 1 ? 'ativo ativo' : 'ativos ativos' }} entre os clientes vinculados.
            </p>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Corretora / instituição</label>
            <select wire:model.live="instituicao" class="select mt-1.5">
                <option value="">Todas as instituições</option>
                @foreach ($instituicoes as $nome)
                    <option value="{{ $nome }}">{{ $nome }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($linhas->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">
                @if ($instituicao !== '')
                    Nenhum ativo em {{ $instituicao }} entre os clientes vinculados.
                @else
                    Nenhum investimento ativo entre os clientes vinculados ainda.
                @endif
            </p>
        </div>
    @else
        <div class="card overflow-x-auto">
            <table class="w-full min-w-[860px] text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-left text-[11px] tracking-wide text-slate-400 uppercase dark:border-white/10">
                        <th class="px-5 py-3 font-semibold">Cliente</th>
                        <th class="px-3 py-3 font-semibold">Instituição</th>
                        <th class="px-3 py-3 font-semibold">Ativo</th>
                        <th class="px-3 py-3 font-semibold">Setor</th>
                        <th class="px-3 py-3 text-right font-semibold">Valor atual</th>
                        <th class="px-3 py-3 text-right font-semibold">Ganho</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($linhas as $linha)
                        @php
                            $ativo = $linha['investment'];
                            $pct = $ativo->gainPercentage();
                        @endphp
                        <tr>
                            <td class="max-w-40 px-5 py-3">
                                <p class="truncate font-medium text-slate-800 dark:text-slate-200">{{ $linha['client_name'] }}</p>
                            </td>
                            <td class="px-3 py-3 text-slate-600 dark:text-slate-400">{{ $ativo->institution ?? '—' }}</td>
                            <td class="max-w-56 px-3 py-3">
                                <p class="truncate text-slate-800 dark:text-slate-200">{{ $ativo->displayName() }}</p>
                            </td>
                            <td class="px-3 py-3 text-slate-600 dark:text-slate-400">{{ $ativo->asset_class->label() }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-slate-800 dark:text-slate-200">
                                {{ Money::compact($ativo->current_amount) }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums">
                                @if ($pct !== null)
                                    <span @class([
                                        'font-medium',
                                        'text-brand-700 dark:text-brand-300' => $pct >= 0,
                                        'text-red-700 dark:text-red-400' => $pct < 0,
                                    ])>
                                        {{ $pct >= 0 ? '+' : '' }}{{ number_format($pct, 2, ',', '.') }}%
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" action="{{ route('profile.switch', $ativo->profile_id) }}">
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
