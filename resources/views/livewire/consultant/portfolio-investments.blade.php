@use('App\Support\Money')

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Investimentos da carteira</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $totalGeral }} {{ $totalGeral === 1 ? 'ativo ativo' : 'ativos ativos' }} entre os clientes vinculados,
                separados por cliente e instituição.
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

    @if ($grouped->isEmpty())
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
        <div class="space-y-3">
            @foreach ($grouped as $clienteNome => $porCliente)
                <div x-data="{ open: false }" class="card overflow-hidden">
                    <button
                        type="button"
                        @click="open = ! open"
                        class="flex w-full flex-wrap items-center justify-between gap-3 px-5 py-4 text-left"
                    >
                        <p class="font-display text-lg font-semibold text-slate-900 dark:text-white sm:text-xl">
                            {{ $clienteNome }}
                        </p>

                        <div class="flex items-center gap-4">
                            <div class="text-right">
                                <p class="figure text-sm font-semibold text-slate-800 dark:text-slate-200">{{ Money::compact($porCliente['total']) }}</p>
                                <p class="text-xs text-slate-400">
                                    {{ $porCliente['quantidade'] }} {{ $porCliente['quantidade'] === 1 ? 'ativo' : 'ativos' }}
                                </p>
                            </div>
                            <x-nav-icon
                                name="chevron"
                                class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200"
                                x-bind:class="{ 'rotate-180': open }"
                            />
                        </div>
                    </button>

                    <div x-show="open" x-cloak x-transition class="divide-y divide-slate-100 border-t border-slate-100 px-5 dark:divide-white/10 dark:border-white/10">
                        @foreach ($porCliente['membros'] as $porMembro)
                            <div class="py-3">
                                @if ($porCliente['separarPorMembro'])
                                    <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400">
                                        {{ $porMembro['nome'] }}
                                    </p>
                                @endif

                                <div @class(['space-y-3', 'mt-2' => $porCliente['separarPorMembro']])>
                                    @foreach ($porMembro['instituicoes'] as $instituicaoNome => $linhasDaInstituicao)
                                        <div>
                                            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ $instituicaoNome }}</p>

                                            <div class="mt-2 overflow-x-auto">
                                                <table class="w-full min-w-[560px] text-sm">
                                                    <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                                                        @foreach ($linhasDaInstituicao as $linha)
                                                            @php
                                                                $ativo = $linha['investment'];
                                                                $pct = $ativo->gainPercentage();
                                                            @endphp
                                                            <tr>
                                                                <td class="max-w-56 py-2 pr-3">
                                                                    <p class="truncate text-slate-800 dark:text-slate-200">{{ $ativo->displayName() }}</p>
                                                                </td>
                                                                <td class="py-2 pr-3 text-slate-600 dark:text-slate-400">{{ $ativo->asset_class->label() }}</td>
                                                                <td class="py-2 pr-3 text-right tabular-nums text-slate-800 dark:text-slate-200">
                                                                    {{ Money::compact($ativo->current_amount) }}
                                                                </td>
                                                                <td class="py-2 pr-3 text-right tabular-nums">
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
                                                                <td class="py-2 text-right">
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
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>
