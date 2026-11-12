<div>
    @use('App\Support\Money')

    <x-modal wire-model="show" max-width="2xl">
        @if ($objetivo)
            <div class="space-y-4" data-goal-history>
                <div class="flex items-baseline justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="truncate text-sm font-semibold text-slate-900 dark:text-white">Aportes: {{ $objetivo->name }}</h2>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Já tem {{ Money::format($acumulado) }} de {{ Money::format($objetivo->estimated_value) }} ({{ number_format($objetivo->progressPercentage(), 1, ',', '.') }}%).
                            @if (bccomp((string) $objetivo->current_amount, '0', 2) > 0)
                                O saldo inicial é {{ Money::format($objetivo->current_amount) }}; os aportes somam a ele.
                            @endif
                        </p>
                    </div>
                    <button type="button" wire:click="close" class="btn-ghost px-2 py-1 text-xs">Fechar</button>
                </div>

                @if (session('goal_history_status'))
                    <p class="rounded-xl bg-brand-50 px-3 py-2 text-xs text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">{{ session('goal_history_status') }}</p>
                @endif

                <form wire:submit="save" class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-white/10" data-contribution-form>
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ $editId === '' ? 'Registrar' : 'Editar lançamento' }}</p>
                    <div class="grid gap-3 @sm:grid-cols-2 @lg:grid-cols-4 *:min-w-0">
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                            <select wire:model="type" class="select mt-1.5 w-full">
                                <option value="aporte">Aporte (guardei)</option>
                                <option value="retirada">Retirada (tirei)</option>
                            </select>
                            @error('type') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor</label>
                            <input type="text" wire:model="amount" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 500,00" data-contribution-amount>
                            @error('amount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
                            <input type="date" wire:model="date" max="{{ $hoje }}" class="input mt-1.5">
                            @error('date') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observação <span class="font-normal text-slate-400">(opcional)</span></label>
                            <input type="text" wire:model="note" maxlength="200" class="input mt-1.5" placeholder="Ex.: 13º salário">
                            @error('note') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        @if ($editId !== '')
                            <button type="button" wire:click="cancelEdit" class="btn-ghost px-3 py-2 text-sm">Cancelar</button>
                        @endif
                        <button type="submit" class="btn-primary px-4 py-2 text-sm" wire:loading.attr="disabled">{{ $editId === '' ? 'Registrar' : 'Salvar alterações' }}</button>
                    </div>
                </form>

                <p class="text-xs text-slate-500 dark:text-slate-400" data-real-pace>
                    Ritmo real: média de <strong class="font-medium">{{ Money::format($mediaTresMeses) }}</strong> por mês nos últimos 3 meses
                    @if ($objetivo->installment_amount !== null && bccomp((string) $objetivo->installment_amount, '0', 2) > 0)
                        (planejado: {{ Money::format($objetivo->installment_amount) }} por mês).
                    @else
                        (você ainda não informou quanto planeja guardar por mês).
                    @endif
                </p>

                @if (count($linhaDoTempo) > 0)
                    <div data-goal-timeline>
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-300">Linha do tempo</p>
                        <ul class="mt-2 space-y-2">
                            @foreach ($linhaDoTempo as $linha)
                                <li class="grid grid-cols-[4.5rem_1fr_auto] items-center gap-3 text-xs tabular-nums" wire:key="mes-{{ $linha['label'] }}">
                                    <span class="text-slate-500 dark:text-slate-400">{{ $linha['label'] }}</span>
                                    <span class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700"><span class="block h-full rounded-full bg-brand-700 dark:bg-brand-400" style="width: {{ $linha['percent'] }}%"></span></span>
                                    <span class="text-right text-slate-700 dark:text-slate-300">
                                        {{ bccomp($linha['total'], '0', 2) >= 0 ? '+' : '−' }}{{ Money::format(ltrim($linha['total'], '-')) }}
                                        <span class="text-slate-400">· {{ Money::format($linha['accumulated']) }} ({{ number_format($linha['percent'], 0, ',', '.') }}%)</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($lancamentos->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400" data-contributions-empty>Nenhum aporte registrado ainda. Registre o que você guarda para acompanhar o ritmo e a previsão.</p>
                @else
                    <ul class="divide-y divide-slate-100 dark:divide-white/10" data-contributions-list>
                        @foreach ($lancamentos as $l)
                            <li class="flex items-start justify-between gap-3 py-2" wire:key="aporte-{{ $l->id }}">
                                <div class="min-w-0">
                                    <p class="text-sm tabular-nums {{ $l->isWithdrawal() ? 'text-red-700 dark:text-red-400' : 'text-slate-800 dark:text-slate-200' }}">
                                        {{ $l->isWithdrawal() ? '−' : '+' }}{{ Money::format(ltrim((string) $l->amount, '-')) }}
                                        <span class="text-xs text-slate-500 dark:text-slate-400">· {{ $l->contributed_on->format('d/m/Y') }}</span>
                                    </p>
                                    @if ($l->note)
                                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $l->note }}</p>
                                    @endif
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <button type="button" wire:click="edit('{{ $l->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                                    <button type="button" wire:click="delete('{{ $l->id }}')" wire:confirm="Excluir este lançamento?" class="btn-ghost px-2 py-1 text-xs text-red-700 dark:text-red-400">Excluir</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </x-modal>
</div>
