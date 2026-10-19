@use('App\Support\Money')

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Sonhos &amp; Objetivos</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Ordenados por prioridade.</p>
        </div>
        <button type="button" wire:click="openForm" class="btn-secondary px-3 py-1.5 text-sm">Novo objetivo</button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Soma dos objetivos</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($totalTarget) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Já acumulado</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800 dark:text-brand-300">{{ Money::format($totalAccumulated) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Guardar por mês</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($totalMonthlyNeeded) }}</p>
            <p class="mt-1 text-xs text-slate-400">Para cumprir os prazos definidos.</p>
        </div>
    </div>

    @if ($goals->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum objetivo cadastrado.</p>
            <p class="mt-1 text-xs text-slate-400">Cadastre um sonho ou meta, com valor e prazo, e acompanhe o quanto já avançou.</p>
        </div>
    @else
        <ul class="space-y-3">
            @foreach ($goals as $objetivo)
                <li class="card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-800 text-xs font-medium text-white">
                                    {{ $objetivo->priority }}
                                </span>
                                <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $objetivo->name }}</p>
                            </div>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $objetivo->funding_method->label() }}
                                @if ($objetivo->target_date) · até {{ $objetivo->target_date->format('m/Y') }} @endif
                                @if ($objetivo->member) · {{ $objetivo->member->name }} @else · do casal @endif
                                @if ($objetivo->linkedInvestment) · vinculado a {{ $objetivo->linkedInvestment->name }} @endif
                                @if ($objetivo->linkedDebt) · consórcio {{ $objetivo->linkedDebt->name }} @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($objetivo->estimated_value) }}</p>
                            @if ($mensal = $objetivo->monthlyNeeded())
                                <p class="text-xs tabular-nums text-slate-500 dark:text-slate-400">{{ Money::format($mensal) }}/mês</p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                        <div class="h-full rounded-full bg-brand-700 dark:bg-brand-400" style="width: {{ $objetivo->progressPercentage() }}%"></div>
                    </div>

                    <div class="mt-2 flex items-baseline justify-between">
                        @if ($objetivo->usesInstallments())
                            @php($parcelas = $objetivo->installmentProgress())
                            <p class="text-sm text-slate-700 dark:text-slate-300">
                                {{ $parcelas['paid'] }} de {{ $parcelas['total'] }} parcelas
                                <span class="text-slate-400">· {{ number_format($objetivo->progressPercentage(), 1, ',', '.') }}%</span>
                            </p>
                            @if ($objetivo->isAchieved())
                                <span class="text-xs text-brand-700 dark:text-brand-300">objetivo alcançado</span>
                            @else
                                <span class="text-xs text-slate-400">faltam {{ $parcelas['total'] - $parcelas['paid'] }} parcelas</span>
                            @endif
                        @else
                            <p class="text-sm text-slate-700 dark:text-slate-300">
                                {{ Money::format($objetivo->accumulated()) }}
                                <span class="text-slate-400">· {{ number_format($objetivo->progressPercentage(), 1, ',', '.') }}%</span>
                            </p>
                            @if ($objetivo->isAchieved())
                                <span class="text-xs text-brand-700 dark:text-brand-300">objetivo alcançado</span>
                            @else
                                <span class="text-xs text-slate-400">faltam {{ Money::format($objetivo->remaining()) }}</span>
                            @endif
                        @endif
                    </div>

                    <div class="mt-3 flex flex-wrap justify-end gap-1">
                        @if (! $objetivo->isAchieved())
                            <button type="button" wire:click="markAchieved('{{ $objetivo->id }}')" wire:confirm="Marcar este objetivo como conquistado?" class="btn-ghost px-2 py-1 text-xs">Marcar como conquistado</button>
                        @endif
                        <button type="button" wire:click="edit('{{ $objetivo->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($achieved->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Conquistados</h2>
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($achieved as $objetivo)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3" wire:key="conquistado-{{ $objetivo->id }}">
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $objetivo->name }}</p>
                        <div class="flex items-center gap-2">
                            <p class="text-sm tabular-nums text-slate-500 dark:text-slate-400">{{ Money::format($objetivo->estimated_value) }}</p>
                            <button type="button" wire:click="reopen('{{ $objetivo->id }}')" class="btn-ghost px-2 py-1 text-xs">Reabrir</button>
                            <button type="button" wire:click="edit('{{ $objetivo->id }}')" class="btn-ghost px-2 py-1 text-xs">Editar</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-modal wire-model="showForm" max-width="2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingId ? 'Editar objetivo' : 'Novo objetivo' }}</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="name" maxlength="120" class="input mt-1.5" placeholder="Ex.: Trocar de carro, Viagem ao Japão">
                    @error('name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Como o objetivo avança</label>
                    <select wire:model.live="linkType" class="select mt-1.5 w-full">
                        <option value="">Pelo dinheiro que eu informar</option>
                        <option value="investment">Pelo saldo de um investimento</option>
                        <option value="debt">Pelas parcelas pagas de um consórcio</option>
                    </select>
                </div>

                @if ($linkType === 'investment')
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Investimento</label>
                        <select wire:model="linkedInvestmentId" class="select mt-1.5 w-full">
                            <option value="">Escolha</option>
                            @foreach ($investimentos as $inv)
                                <option value="{{ $inv->id }}">{{ $inv->institution ? $inv->institution.' · ' : '' }}{{ $inv->displayName() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">O quanto já guardou é o saldo desse investimento.</p>
                        @error('linkedInvestmentId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @elseif ($linkType === 'debt')
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Consórcio</label>
                        <select wire:model.live="linkedDebtId" class="select mt-1.5 w-full">
                            <option value="">Escolha</option>
                            @foreach ($consorcios as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} · carta de {{ Money::format($c->credit_amount) }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">O valor do objetivo é a carta e o avanço é parcelas pagas por total de parcelas. Só aparecem consórcios sem objetivo.</p>
                        @error('linkedDebtId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor do objetivo</label>
                    <input type="text" wire:model="estimatedValue" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 100000,00">
                    @error('estimatedValue') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data-alvo <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="date" wire:model="targetDate" class="input mt-1.5">
                    @error('targetDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @if ($linkType !== 'debt')
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Como vai juntar</label>
                        <select wire:model.live="fundingMethod" class="select mt-1.5 w-full">
                            @foreach ($metodos as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($fundingMethod === 'installments')
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor da parcela</label>
                            <input type="text" wire:model="installmentAmount" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 1500,00">
                            @error('installmentAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @endif

                @if ($linkType === '')
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quanto já tem</label>
                        <input type="text" wire:model="currentAmount" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 5000,00">
                        @error('currentAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Prioridade</label>
                    <input type="number" wire:model="priority" min="1" max="999" class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-400">1 é a mais importante.</p>
                    @error('priority') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @if ($editingId)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Situação</label>
                        <select wire:model="status" class="select mt-1.5 w-full">
                            <option value="active">Em andamento</option>
                            <option value="achieved">Conquistado</option>
                        </select>
                    </div>
                @endif

                @if ($membros->count() > 1)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">De quem é</label>
                        <select wire:model.live="memberId" class="select mt-1.5 w-full">
                            <option value="">Do casal</option>
                            @foreach ($membros as $m)
                                <option value="{{ $m->id }}">{{ $m->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($memberId !== '')
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 pb-2 text-xs text-slate-600 dark:text-slate-300">
                                <input type="checkbox" wire:model="isPrivate" class="rounded border-slate-300">
                                Só esta pessoa vê
                            </label>
                        </div>
                    @endif
                @endif

                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações <span class="font-normal text-slate-400">(opcional)</span></label>
                    <textarea wire:model="notes" rows="2" maxlength="2000" class="input mt-1.5"></textarea>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                @if ($editingId)
                    <button type="button" wire:click="delete('{{ $editingId }}')" wire:confirm="Excluir este objetivo? Isso não apaga o investimento nem o consórcio ligados a ele." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Excluir objetivo</button>
                @else
                    <span></span>
                @endif
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>

</div>
