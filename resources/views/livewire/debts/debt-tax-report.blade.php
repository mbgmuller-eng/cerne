@use('App\Support\Money')

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Dívidas e ônus reais</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">O que se devia em 31/12, por dívida e credor, para conferir a declaração do imposto de renda.</p>
        </div>
        <a href="{{ route('debts.index') }}" class="btn-ghost px-3 py-1.5 text-sm">Voltar às dívidas</a>
    </div>

    <div class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Ano-base</label>
            <select wire:model.live="ano" class="select mt-1.5">
                @foreach ($anos as $a)
                    <option value="{{ $a }}">{{ $a }}</option>
                @endforeach
            </select>
        </div>
        @if ($membros->count() > 1)
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa (cada um declara no seu CPF)</label>
                <select wire:model.live="pessoa" class="select mt-1.5">
                    @foreach ($membros as $m)
                        <option value="{{ $m->id }}">{{ $m->name }}</option>
                    @endforeach
                    <option value="todos">Todos juntos</option>
                </select>
            </div>
        @endif
        <div class="ml-auto flex gap-2">
            <button type="button" wire:click="baixarCsv" class="btn-secondary px-3 py-1.5">Baixar CSV</button>
            <button type="button" wire:click="baixarPdf" class="btn-secondary px-3 py-1.5">Baixar PDF</button>
        </div>
    </div>

    <p class="rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
        É uma conferência com o que está registrado no Cerne, não a declaração. O saldo em cada data vem do cronograma das parcelas, sem
        correção que o banco aplique por fora. Dívida do casal (sem dono) aparece para os dois: declare conforme a titularidade do contrato.
        Confira com o informe do credor e com o seu contador.
    </p>

    @if (! $relatorio['has_data'])
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma dívida em {{ $relatorio['year'] }} para {{ $nomeDaPessoa }}.</p>
        </div>
    @else
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Situação em 31/12</h2>

            @if ($relatorio['rows'] === [])
                <p class="mt-3 text-xs text-slate-400">Nenhuma dívida com cronograma devida em 31/12/{{ $relatorio['year'] - 1 }} ou 31/12/{{ $relatorio['year'] }}.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead>
                            <tr class="text-left text-xs text-slate-400">
                                <th class="py-2 pr-3 font-medium">Dívida</th>
                                <th class="py-2 pr-3 font-medium">Credor</th>
                                <th class="py-2 pr-3 text-right font-medium">31/12/{{ $relatorio['year'] - 1 }}</th>
                                <th class="py-2 text-right font-medium">31/12/{{ $relatorio['year'] }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                            @foreach ($relatorio['rows'] as $l)
                                <tr wire:key="ir-{{ $l['debt']->id }}" class="text-slate-800 dark:text-slate-200">
                                    <td class="py-2.5 pr-3">
                                        <a href="{{ route('debts.show', $l['debt']) }}" class="hover:underline">{{ $l['debt']->name }}</a>
                                        <p class="text-xs text-slate-400">
                                            {{ $l['contracted_on'] ? 'contratada em '.$l['contracted_on']->format('d/m/Y').($l['contract_estimated'] ? ' (estimada pelo 1º vencimento)' : '') : '' }}{{ $l['joint'] ? ' · do casal' : '' }}
                                        </p>
                                    </td>
                                    <td class="py-2.5 pr-3">
                                        {{ $l['creditor'] ?: '—' }}
                                        <p class="text-xs {{ $l['document'] ? 'text-slate-400' : 'text-amber-700 dark:text-amber-400' }}">{{ $l['document'] ?: 'sem CPF ou CNPJ cadastrado' }}</p>
                                    </td>
                                    <td class="py-2.5 pr-3 text-right tabular-nums">{{ Money::format($l['previous']) }}</td>
                                    <td class="py-2.5 text-right tabular-nums">{{ Money::format($l['current']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-slate-200 text-sm font-medium text-slate-900 dark:border-white/10 dark:text-white">
                                <td class="py-2.5 pr-3" colspan="2">Total</td>
                                <td class="py-2.5 pr-3 text-right tabular-nums">{{ Money::format($relatorio['total_previous']) }}</td>
                                <td class="py-2.5 text-right tabular-nums">{{ Money::format($relatorio['total_current']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </section>

        @if ($relatorio['unscheduled'] !== [])
            <section class="card p-5">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Sem cronograma</h2>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Dívidas ainda sem acordo: valem o saldo que você informou, na data em que informou. Fora dos totais acima.</p>
                <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($relatorio['unscheduled'] as $l)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm text-slate-800 dark:text-slate-200" wire:key="ir-aberta-{{ $l['debt']->id }}">
                            <span>{{ $l['debt']->name }}<span class="block text-xs text-slate-400">{{ $l['debt']->creditor ?: 'credor não informado' }}{{ $l['debt']->creditor_document ? ' · '.$l['debt']->creditor_document : '' }}</span></span>
                            <span class="text-right tabular-nums">{{ Money::format($l['balance']) }}<span class="block text-xs text-slate-400">informado em {{ $l['updated_on']?->format('d/m/Y') ?? '—' }}</span></span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endif
</div>
