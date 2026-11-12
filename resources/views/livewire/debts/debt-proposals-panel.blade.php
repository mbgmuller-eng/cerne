<div>
    @use('App\Support\Money')

    @if ($podeUsar || count($propostas) > 0)
        <section class="card p-5" data-proposals-panel>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Propostas de acordo</h2>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        As ofertas do credor, da mais nova para a mais antiga. Marque duas ou mais para comparar lado a lado.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if (count($selected) > 0)
                        <button type="button" wire:click="clearSelection" class="btn-ghost px-3 py-1.5 text-xs">Limpar seleção ({{ count($selected) }})</button>
                    @endif
                    <button type="button" wire:click="openNew" class="btn-primary px-3 py-1.5 text-xs" data-new-proposal>Nova proposta</button>
                </div>
            </div>

            @if (session('proposals_status'))
                <p class="mt-3 rounded-xl bg-brand-50 px-3 py-2 text-xs text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">{{ session('proposals_status') }}</p>
            @endif

            @if ($showForm)
                <form wire:submit="save" class="mt-4 space-y-4 rounded-xl border border-slate-200 p-4 dark:border-white/10" data-proposal-form>
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $editKey === '' ? 'Nova proposta' : 'Editar proposta' }}</p>
                    <div class="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3 *:min-w-0">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome da proposta</label>
                            <input type="text" wire:model="pLabel" maxlength="40" class="input mt-1.5" placeholder="Ex.: Oferta do Banco X">
                            @error('pLabel') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data em que foi recebida</label>
                            <input type="date" wire:model="pReceivedOn" max="{{ $hoje }}" class="input mt-1.5">
                            @error('pReceivedOn') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Total a pagar</label>
                            <input type="text" wire:model="pTotal" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 3600,00">
                            <p class="mt-1 text-xs text-slate-400">Somando a entrada e as parcelas.</p>
                            @error('pTotal') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Entrada <span class="font-normal text-slate-400">(opcional)</span></label>
                            <input type="text" wire:model="pDown" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 600,00">
                            @error('pDown') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parcelas</label>
                            <input type="number" wire:model="pInstallments" min="1" max="480" class="input mt-1.5">
                            <p class="mt-1 text-xs text-slate-400">Depois da entrada.</p>
                            @error('pInstallments') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        @if ($editKey === '' || $editandoSimulacaoUnica)
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Saldo na época <span class="font-normal text-slate-400">(opcional)</span></label>
                                <input type="text" wire:model="pBalance" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 4250,00">
                                <p class="mt-1 text-xs text-slate-400">Sem valor, vale o saldo de hoje ({{ Money::format($debt->current_balance) }}).</p>
                                @error('pBalance') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div class="@sm:col-span-2 @lg:col-span-3">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observação <span class="font-normal text-slate-400">(opcional)</span></label>
                            <input type="text" wire:model="pNote" maxlength="200" class="input mt-1.5" placeholder="Ex.: falei com a Maria; vale até o fim do mês">
                            @error('pNote') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="closeForm" class="btn-ghost px-3 py-2 text-sm">Cancelar</button>
                        <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar proposta</button>
                    </div>
                </form>
            @endif

            @if (count($propostas) === 0)
                <p class="mt-4 text-sm text-slate-500 dark:text-slate-400" data-proposals-empty>
                    Nenhuma proposta guardada ainda. Cadastre as ofertas do credor em "Nova proposta" (ou compare e guarde em "Simular acordo") para acompanhar a negociação e comparar depois.
                </p>
            @else
                <ul class="mt-4 divide-y divide-slate-100 dark:divide-white/10" data-proposals-list>
                    @foreach ($propostas as $p)
                        <li class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:justify-between" wire:key="proposta-{{ $p['key'] }}">
                            <div class="flex min-w-0 gap-3">
                                <input type="checkbox" wire:model.live="selected" value="{{ $p['key'] }}" id="sel-{{ $p['key'] }}" class="mt-1 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500" aria-label="Comparar {{ $p['label'] }}">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $p['label'] }}</p>
                                    <p class="text-sm tabular-nums text-slate-700 dark:text-slate-300">
                                        {{ Money::format($p['total']) }}
                                        @if (bccomp($p['down'], '0', 2) > 0)
                                            · entrada de {{ Money::format($p['down']) }} + {{ $p['installments'] }} {{ $p['installments'] === 1 ? 'parcela' : 'parcelas' }}
                                        @else
                                            · em {{ $p['installments'] }} {{ $p['installments'] === 1 ? 'vez' : 'vezes' }}
                                        @endif
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        recebida em {{ \Carbon\CarbonImmutable::parse($p['received_on'])->format('d/m/Y') }} · saldo na época {{ Money::format($p['balance_at']) }}
                                        @if (bccomp($p['balance_at'], $p['total'], 2) > 0)
                                            · desconto de {{ number_format((float) bcsub($p['balance_at'], $p['total'], 2) / (float) $p['balance_at'] * 100, 1, ',', '.') }}%
                                        @endif
                                    </p>
                                    @if ($p['note'] !== '')
                                        <p class="mt-0.5 text-xs italic text-slate-500 dark:text-slate-400">{{ $p['note'] }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2 pl-7 sm:pl-0">
                                @if ($podeUsar)
                                    <button type="button" wire:click="useProposal('{{ $p['key'] }}')" class="btn-secondary px-2.5 py-1 text-xs">Usar esta proposta</button>
                                @endif
                                <button type="button" wire:click="openEdit('{{ $p['key'] }}')" class="btn-ghost px-2.5 py-1 text-xs">Editar</button>
                                <button type="button" wire:click="delete('{{ $p['key'] }}')" wire:confirm="Excluir esta proposta?" class="btn-ghost px-2.5 py-1 text-xs text-red-700 dark:text-red-400">Excluir</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (count($selected) === 1)
                <p class="mt-3 text-xs text-slate-400">Marque mais uma proposta para comparar.</p>
            @endif

            @if ($comparacao)
                <div class="mt-4 overflow-x-auto rounded-xl border border-brand-200 bg-brand-50/40 p-4 dark:border-brand-500/30 dark:bg-brand-500/5" data-proposals-comparison>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Comparação ({{ count($comparacao['rows']) }} propostas)</p>
                    <table class="mt-3 w-full min-w-[40rem] text-left text-sm tabular-nums">
                        <thead>
                            <tr class="text-xs text-slate-500 dark:text-slate-400">
                                <th class="py-1 pr-3 font-medium">Proposta</th>
                                <th class="py-1 pr-3 font-medium">Total</th>
                                <th class="py-1 pr-3 font-medium">Entrada</th>
                                <th class="py-1 pr-3 font-medium">Parcelas</th>
                                <th class="py-1 pr-3 font-medium">Desconto na época</th>
                                <th class="py-1 pr-3 font-medium">Contra o saldo de hoje</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-100 dark:divide-white/10">
                            @foreach ($comparacao['rows'] as $i => $r)
                                <tr wire:key="cmp-{{ $r['key'] }}">
                                    <td class="py-2 pr-3 align-top text-slate-800 dark:text-slate-200">
                                        {{ $r['label'] }}
                                        <span class="block text-xs font-normal text-slate-500 dark:text-slate-400">{{ \Carbon\CarbonImmutable::parse($r['received_on'])->format('d/m/Y') }}</span>
                                        @if ($comparacao['best_total'] === $i) <span class="badge bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300" data-best-total>menor custo</span> @endif
                                        @if ($comparacao['lightest_month'] === $i && $comparacao['best_total'] !== $i) <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300" data-lightest>menor parcela</span> @endif
                                    </td>
                                    <td class="py-2 pr-3 align-top">{{ Money::format($r['total']) }}</td>
                                    <td class="py-2 pr-3 align-top">{{ bccomp($r['down'], '0', 2) > 0 ? Money::format($r['down']) : '—' }}</td>
                                    <td class="py-2 pr-3 align-top">{{ $r['installments'] }} × {{ Money::format($r['monthly']) }}</td>
                                    <td class="py-2 pr-3 align-top">
                                        @if (bccomp($r['discount_then'], '0', 2) > 0)
                                            <span class="text-accent-700 dark:text-accent-400">{{ Money::format($r['discount_then']) }} ({{ number_format($r['discount_then_percent'], 1, ',', '.') }}%)</span>
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">sobre {{ Money::format($r['balance_at']) }}</span>
                                        @else
                                            <span class="text-slate-600 dark:text-slate-300">sem desconto</span>
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">saldo na época {{ Money::format($r['balance_at']) }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 align-top">
                                        @if (bccomp($r['discount_today'], '0', 2) > 0)
                                            <span class="text-accent-700 dark:text-accent-400">{{ Money::format($r['discount_today']) }} a menos ({{ number_format($r['discount_today_percent'], 1, ',', '.') }}%)</span>
                                        @elseif (bccomp($r['discount_today'], '0', 2) < 0)
                                            <span class="text-red-700 dark:text-red-400">{{ Money::format(ltrim($r['discount_today'], '-')) }} a mais</span>
                                        @else
                                            <span class="text-slate-600 dark:text-slate-300">igual ao saldo</span>
                                        @endif
                                        @if ($r['implicit_rate'] !== null)
                                            <span class="block text-xs text-slate-500 dark:text-slate-400">parcelar custa ~{{ number_format($r['implicit_rate'], 2, ',', '.') }}% ao mês frente a "{{ $r['reference'] }}"</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        "Desconto na época" usa o saldo da dívida quando a proposta veio. "Contra o saldo de hoje" ({{ Money::format($debt->current_balance) }}) põe todas na mesma régua. Não considera o que o dinheiro renderia em outro lugar: confira tudo no contrato.
                    </p>
                </div>
            @endif
        </section>
    @endif
</div>
