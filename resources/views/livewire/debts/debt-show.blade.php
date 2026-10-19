@use('App\Support\Money')

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $debt->kind->label() }}{{ $debt->creditor ? ' · '.$debt->creditor : '' }}</p>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $debt->name }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                @if ($debt->status->isUnscheduled())
                    <span>{{ $debt->status->label() }}: sem parcelas até o acordo, mas já conta no total devedor.</span>
                @else
                    Parcelas em <strong class="font-medium">{{ $debt->category?->name }}{{ $debt->subcategory ? ' › '.$debt->subcategory->name : '' }}</strong>
                    @if ($debt->isConsortium()) · {{ $debt->consortium_type?->label() }} · <span>{{ $emCarta ? 'não contemplado' : 'contemplado' }}</span> @endif
                    @if ($debt->status === \App\Enums\DebtStatus::PaidOff) · <span class="text-accent-700 dark:text-accent-400">quitada</span> @endif
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('debts.index') }}" class="btn-ghost px-3 py-1.5 text-sm">Voltar</a>
            @if ($debt->isConsortium() && $debt->status === \App\Enums\DebtStatus::Active)
                @if ($debt->adjustment_index)
                    <button type="button" wire:click="openAdjustment" class="btn-secondary px-3 py-1.5 text-sm">Registrar reajuste</button>
                @endif
                @if ($emCarta)
                    <button type="button" wire:click="openContemplation" class="btn-secondary px-3 py-1.5 text-sm">Registrar contemplação</button>
                @endif
            @endif
            @if ($debt->status->isUnscheduled())
                <button type="button" wire:click="openBalance" class="btn-secondary px-3 py-1.5 text-sm">Atualizar saldo</button>
                <button type="button" wire:click="openAgreementSim" class="btn-secondary px-3 py-1.5 text-sm">Simular acordo</button>
                <button type="button" wire:click="openAgreement" class="btn-secondary px-3 py-1.5 text-sm">Registrar acordo</button>
                <button type="button" wire:click="markPaidOff" wire:confirm="Dar esta dívida como quitada? Use quando ela foi paga por fora, perdoada ou prescreveu." class="btn-ghost px-3 py-1.5 text-sm">Marcar como quitada</button>
            @endif
            @if ($podeSimular)
                <button type="button" wire:click="openSim" class="btn-secondary px-3 py-1.5 text-sm">Simular antecipação ou portabilidade</button>
            @endif
            <button type="button" wire:click="openEdit" class="btn-secondary px-3 py-1.5 text-sm">Editar contrato</button>
        </div>
    </div>

    @if ($debt->status->isUnscheduled())
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="card p-5">
                <p class="eyebrow">Saldo atual</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($saldo) }}</p>
                <p class="mt-1 text-xs text-slate-400">informado em {{ $debt->balance_updated_on?->format('d/m/Y') ?? '—' }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Valor original</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($debt->principal) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Situação</p>
                <p class="mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ $debt->status->label() }}</p>
                <p class="mt-1 text-xs text-slate-400">as parcelas só existem depois do acordo</p>
            </div>
        </div>
    @else
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-5">
            <p class="eyebrow">{{ $emCarta ? 'Carta' : $debt->balanceLabel() }}</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($emCarta ? $debt->credit_amount : $saldo) }}</p>
            @if ($emCarta)
                <p class="mt-1 text-xs text-slate-400">não é dívida nem patrimônio; é o que o consórcio vai liberar</p>
            @endif
        </div>
        <div class="card p-5">
            <p class="eyebrow">Parcelas pagas</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ $quantidadePagas }} de {{ $debt->term }}</p>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                <div class="h-full rounded-full bg-accent-600 dark:bg-accent-400" style="width: {{ $debt->term > 0 ? floor($quantidadePagas / $debt->term * 100) : 0 }}%"></div>
            </div>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Próxima parcela</p>
            @if ($proxima)
                <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($proxima->amount) }}</p>
                <p class="mt-1 text-xs {{ $proxima->isOverdue() ? 'text-red-700 dark:text-red-400' : 'text-slate-400' }}">{{ $proxima->isOverdue() ? 'venceu em ' : 'vence em ' }}{{ $proxima->due_date->format('d/m/Y') }}</p>
            @else
                <p class="mt-2 text-2xl font-medium text-accent-700 dark:text-accent-400">Quitada</p>
            @endif
        </div>
        <div class="card p-5">
            <p class="eyebrow">{{ $custoJuros !== null ? 'Juros e taxas do contrato' : 'Já pago' }}</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($custoJuros ?? $totalPago) }}</p>
            @if ($custoJuros !== null)
                <p class="mt-1 text-xs text-slate-400">{{ Money::format($totalPago) }} já pagos em parcelas</p>
            @endif
        </div>
    </div>

    @endif

    @if ($debt->isConsortium())
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Consórcio</h2>
            <dl class="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-xs text-slate-400">Carta</dt><dd class="text-slate-800 dark:text-slate-200">{{ Money::format($debt->credit_amount) }}</dd></div>
                @if ($debt->consortium_group)
                    <div><dt class="text-xs text-slate-400">Grupo e cota</dt><dd class="text-slate-800 dark:text-slate-200">{{ $debt->consortium_group }}</dd></div>
                @endif
                @if ($debt->consortium_contract)
                    <div><dt class="text-xs text-slate-400">Contrato</dt><dd class="text-slate-800 dark:text-slate-200">{{ $debt->consortium_contract }}</dd></div>
                @endif
                <div><dt class="text-xs text-slate-400">Parcela</dt><dd class="text-slate-800 dark:text-slate-200">{{ $debt->flex_percent ? 'flex: '.$debt->flex_percent.'% menor até a contemplação' : ($debt->is_flex ? 'flex (menor até ser contemplado)' : 'fixa') }}</dd></div>
                @if ($estimativaCheia = $debt->estimatedFullInstallment())
                    <div><dt class="text-xs text-slate-400">Parcela depois da contemplação (estimativa, sem lance)</dt><dd class="text-slate-800 dark:text-slate-200">{{ Money::format($estimativaCheia) }}<span class="block text-xs text-slate-400">pelo extrato de {{ $debt->statement_on?->format('d/m/Y') ?? 'data não informada' }}</span></dd></div>
                @endif
                @if ($quantidadePuladas > 0)
                    <div><dt class="text-xs text-slate-400">Parcelas puladas</dt><dd class="text-slate-800 dark:text-slate-200">{{ $quantidadePuladas }} (valor diluído nas seguintes)</dd></div>
                @endif
                @if ($debt->adjustment_index)
                    <div><dt class="text-xs text-slate-400">Reajuste</dt><dd class="text-slate-800 dark:text-slate-200">{{ $debt->adjustment_index->label() }}, a cada {{ $debt->adjustment_every_months }} {{ $debt->adjustment_every_months === 1 ? 'mês' : 'meses' }}</dd></div>
                    @if ($debt->next_adjustment_on && $debt->status === \App\Enums\DebtStatus::Active)
                        <div><dt class="text-xs text-slate-400">Próximo reajuste</dt><dd class="{{ $debt->next_adjustment_on->isPast() ? 'text-red-700 dark:text-red-400' : 'text-slate-800 dark:text-slate-200' }}">{{ $debt->next_adjustment_on->format('d/m/Y') }}</dd></div>
                    @endif
                @endif
                @if ($debt->contemplated_on)
                    <div><dt class="text-xs text-slate-400">Contemplado em</dt><dd class="text-slate-800 dark:text-slate-200">{{ $debt->contemplated_on->format('d/m/Y') }} · {{ $debt->contemplation_mode?->label() }}{{ $debt->bid_amount ? ' de '.Money::format($debt->bid_amount) : '' }}</dd></div>
                    <div><dt class="text-xs text-slate-400">Crédito liberado</dt><dd class="text-slate-800 dark:text-slate-200">{{ Money::format($debt->credit_released ?? $debt->credit_amount) }}</dd></div>
                @endif
                @if ($objetivo)
                    <div><dt class="text-xs text-slate-400">Objetivo</dt><dd class="text-slate-800 dark:text-slate-200">{{ $objetivo->name }}: {{ $quantidadePagas }} de {{ $debt->term }} parcelas</dd></div>
                @endif
            </dl>

            @if ($ajustes->isNotEmpty())
                <h3 class="mt-5 text-xs font-semibold text-slate-500 dark:text-slate-400">Reajustes registrados</h3>
                <ul class="mt-2 divide-y divide-slate-100 text-sm dark:divide-white/10">
                    @foreach ($ajustes as $a)
                        <li wire:key="ajuste-{{ $a->id }}" class="flex flex-wrap justify-between gap-2 py-2 text-slate-700 dark:text-slate-300">
                            <span>{{ $a->applied_on->format('d/m/Y') }} · {{ number_format((float) $a->percent, 2, ',', '.') }}%</span>
                            <span class="tabular-nums text-xs text-slate-500 dark:text-slate-400">carta {{ Money::format($a->credit_before) }} → {{ Money::format($a->credit_after) }} · parcela {{ Money::format($a->installment_before) }} → {{ Money::format($a->installment_after) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @if ($debt->agreement_on)
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Acordo</h2>
            <p class="mt-2 text-sm text-slate-700 dark:text-slate-300">
                Fechado em {{ $debt->agreement_on->format('d/m/Y') }} por {{ Money::format($debt->agreement_total) }}
                @if (bccomp((string) $debt->agreement_discount, '0', 2) > 0) · desconto de <strong class="font-medium">{{ Money::format($debt->agreement_discount) }}</strong> sobre o saldo da época @endif
            </p>
        </section>
    @endif

    @if ($debt->status->isUnscheduled())
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Sem parcelas ainda</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">Atualize o saldo quando o credor informar o valor corrigido. Quando houver acordo, use "Registrar acordo": o Cerne cria as parcelas, que passam a aparecer nas parcelas do mês e viram despesa ao serem pagas.</p>
        </section>
    @else
    <section class="card p-5">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Parcelas</h2>
        @error('skip') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Cada parcela paga fica ligada a uma despesa do fluxo de caixa, na categoria do momento. Desfazer solta a ligação e não apaga a despesa.</p>

        <div class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[34rem] text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-400">
                        <th class="py-2 pr-3 font-medium">Nº</th>
                        <th class="py-2 pr-3 font-medium">Vencimento</th>
                        <th class="py-2 pr-3 text-right font-medium">Valor</th>
                        <th class="py-2 pr-3 font-medium">Situação</th>
                        <th class="py-2 text-right font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-white/10">
                    @foreach ($parcelas as $p)
                        <tr wire:key="parcela-{{ $p->id }}" class="{{ $p->isPaid() ? 'text-slate-500 dark:text-slate-400' : 'text-slate-800 dark:text-slate-200' }}">
                            <td class="py-2.5 pr-3 tabular-nums">{{ $p->number }}</td>
                            <td class="py-2.5 pr-3 tabular-nums">{{ $p->due_date->format('d/m/Y') }}</td>
                            <td class="py-2.5 pr-3 text-right tabular-nums" title="Amortização {{ Money::format($p->principal_amount) }} · juros {{ Money::format($p->interest_amount) }} · taxas {{ Money::format($p->fees_amount) }}">{{ Money::format($p->paid_amount ?? $p->amount) }}</td>
                            <td class="py-2.5 pr-3">
                                @if ($p->isPaid())
                                    <span class="badge bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">paga em {{ $p->paid_on?->format('d/m/Y') }}</span>
                                    @if ($p->expense)
                                        <span class="ml-1 text-xs text-slate-400">{{ $p->expense->category?->name }}</span>
                                    @else
                                        <span class="ml-1 text-xs text-slate-400">antes do Cerne</span>
                                    @endif
                                @elseif ($p->status === \App\Enums\DebtInstallmentStatus::Diluted)
                                    <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300" title="Não foi paga: o valor foi diluído nas parcelas seguintes">pulada</span>
                                @elseif ($p->isOverdue())
                                    <span class="badge bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300">atrasada</span>
                                @else
                                    <span class="badge bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">em aberto</span>
                                @endif
                            </td>
                            <td class="py-2.5 text-right">
                                <span class="inline-flex flex-wrap justify-end gap-1">
                                    @if ($p->isPaid())
                                        <button type="button" wire:click="unlink('{{ $p->id }}')" wire:confirm="Desfazer o pagamento desta parcela? A despesa continua no fluxo de caixa." class="btn-ghost px-2 py-1 text-xs">Desfazer</button>
                                    @elseif ($p->status === \App\Enums\DebtInstallmentStatus::Diluted)
                                        <button type="button" wire:click="reopen('{{ $p->id }}')" class="btn-ghost px-2 py-1 text-xs">Reabrir</button>
                                    @else
                                        @if ($debt->isConsortium() && $p->number < $ultimaPaga)
                                            <button type="button" wire:click="skip('{{ $p->id }}')" wire:confirm="Marcar esta parcela como pulada? Ela não foi paga e o valor foi diluído nas seguintes." class="btn-ghost px-2 py-1 text-xs">Pulada</button>
                                        @endif
                                        <button type="button" wire:click="openPay('{{ $p->id }}')" class="btn-secondary px-2 py-1 text-xs">Pagar</button>
                                        <button type="button" wire:click="openLink('{{ $p->id }}')" class="btn-ghost px-2 py-1 text-xs">Vincular despesa</button>
                                        <button type="button" wire:click="openInstallment('{{ $p->id }}')" class="btn-ghost px-2 py-1 text-xs">Ajustar</button>
                                    @endif
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif

    @if ($debt->notes)
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Observações</h2>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $debt->notes }}</p>
        </section>
    @endif

    {{-- Pagar --}}
    <x-modal wire-model="showPay">
        <form wire:submit="pay" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Pagar parcela</h2>
                <button type="button" wire:click="$set('showPay', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data do pagamento</label>
                    <input type="date" wire:model="payDate" class="input mt-1.5">
                    @error('payDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor pago</label>
                    <input type="text" wire:model="payAmount" inputmode="decimal" class="input mt-1.5">
                    @error('payAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta de onde saiu <span class="font-normal text-slate-400">(opcional)</span></label>
                    <select wire:model="payAccountId" class="select mt-1.5 w-full">
                        <option value="">Nenhuma</option>
                        @foreach ($contas as $c)
                            <option value="{{ $c->id }}">{{ $c->bank_name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="text-xs text-slate-400">Cria a despesa de {{ $debt->category?->name }}{{ $debt->subcategory ? ' › '.$debt->subcategory->name : '' }} e, com conta, debita o saldo dela. O valor pode ser diferente do previsto (juros de mora, desconto).</p>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Registrar pagamento</button></div>
        </form>
    </x-modal>

    {{-- Vincular a uma despesa que já existe --}}
    <x-modal wire-model="showLink">
        <form wire:submit="linkExpense" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Vincular a uma despesa</h2>
                <button type="button" wire:click="$set('showLink', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            @if ($candidatas->isEmpty())
                <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma despesa livre perto do vencimento desta parcela.</p>
                <p class="text-xs text-slate-400">Lance a despesa no fluxo de caixa e volte aqui, ou use "Pagar" para criar a despesa a partir da parcela.</p>
            @else
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Qual despesa pagou esta parcela?</label>
                    <select wire:model="linkExpenseId" class="select mt-1.5 w-full">
                        <option value="">Escolha</option>
                        @foreach ($candidatas as $e)
                            <option value="{{ $e->id }}">{{ $e->expense_date->format('d/m') }} · {{ $e->description }} · {{ Money::format($e->amount) }}{{ $e->category ? ' · '.$e->category->name : '' }}</option>
                        @endforeach
                    </select>
                    @error('linkExpenseId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <label class="inline-flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="linkReclassify" class="rounded border-slate-300">
                    Usar a categoria do contrato ({{ $debt->category?->name }}{{ $debt->subcategory ? ' › '.$debt->subcategory->name : '' }}) nesta despesa
                </label>
                <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Vincular</button></div>
            @endif
        </form>
    </x-modal>

    {{-- Ajustar uma parcela em aberto --}}
    <x-modal wire-model="showInstallment">
        <form wire:submit="saveInstallment" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Ajustar parcela</h2>
                <button type="button" wire:click="$set('showInstallment', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor</label>
                    <input type="text" wire:model="installmentAmount" inputmode="decimal" class="input mt-1.5">
                    @error('installmentAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vencimento</label>
                    <input type="date" wire:model="installmentDate" class="input mt-1.5">
                    @error('installmentDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-slate-400">Use para fazer a parcela bater com o boleto do banco. A diferença de valor conta como juros, e o saldo devedor continua fechando com o contrato.</p>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button></div>
        </form>
    </x-modal>

    {{-- Reajuste da carta --}}
    <x-modal wire-model="showAdjustment">
        <form wire:submit="saveAdjustment" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Registrar reajuste</h2>
                <button type="button" wire:click="$set('showAdjustment', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Percentual do {{ $debt->adjustment_index?->label() }} (%)</label>
                    <input type="text" wire:model="adjustmentPercent" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 4,5">
                    @error('adjustmentPercent') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data do reajuste</label>
                    <input type="date" wire:model="adjustmentDate" class="input mt-1.5">
                    @error('adjustmentDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
            <p class="text-xs text-slate-400">A carta e as parcelas em aberto sobem nesse percentual{{ $objetivo ? ', e o valor do objetivo "'.$objetivo->name.'" também' : '' }}. O Cerne avisa na data, mas o percentual é o que a administradora aplicou.</p>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Aplicar reajuste</button></div>
        </form>
    </x-modal>

    {{-- Contemplação --}}
    <x-modal wire-model="showContemplation" max-width="2xl">
        <form wire:submit="saveContemplation" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Registrar contemplação</h2>
                <button type="button" wire:click="$set('showContemplation', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data da contemplação</label>
                    <input type="date" wire:model="contemplatedOn" class="input mt-1.5">
                    @error('contemplatedOn') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Como foi</label>
                    <select wire:model.live="contemplationMode" class="select mt-1.5 w-full">
                        @foreach ($modosContemplacao as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($contemplationMode !== 'draw')
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor do lance</label>
                        <input type="text" wire:model="bidAmount" inputmode="decimal" class="input mt-1.5">
                        @error('bidAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Crédito liberado <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="text" wire:model="creditReleased" inputmode="decimal" class="input mt-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor da parcela daqui em diante</label>
                    <input type="text" wire:model="newInstallmentAmount" inputmode="decimal" class="input mt-1.5">
                    @error('newInstallmentAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parcelas que ainda faltam</label>
                    <input type="number" wire:model="remainingInstallments" min="1" max="480" class="input mt-1.5">
                    @error('remainingInstallments') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria das parcelas</label>
                    <select wire:model.live="contemplationCategoryId" class="select mt-1.5 w-full">
                        <option value="">Escolha</option>
                        @foreach ($categorias as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('contemplationCategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Subcategoria</label>
                    <select wire:model="contemplationSubcategoryId" class="select mt-1.5 w-full">
                        <option value="">Nenhuma</option>
                        @foreach ($categorias->firstWhere('id', $contemplationCategoryId)?->subcategories ?? [] as $sub)
                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if ($objetivo)
                <label class="inline-flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="markGoalAchieved" class="rounded border-slate-300">
                    Marcar o objetivo "{{ $objetivo->name }}" como alcançado
                </label>
            @endif
            <p class="text-xs text-slate-400">As parcelas em aberto são refeitas com o novo valor e a quantidade que falta (o lance pode baixar a parcela; o sorteio, numa parcela flex, pode aumentá-la). As parcelas já pagas continuam como investimento. Daqui em diante, o que falta pagar passa a contar como dívida.</p>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Registrar contemplação</button></div>
        </form>
    </x-modal>

    {{-- Atualizar saldo da dívida em aberto --}}
    <x-modal wire-model="showBalance">
        <form wire:submit="saveBalance" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Atualizar saldo</h2>
                <button type="button" wire:click="$set('showBalance', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Saldo atual</label>
                    <input type="text" wire:model="balanceAmount" inputmode="decimal" class="input mt-1.5">
                    @error('balanceAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Informado em</label>
                    <input type="date" wire:model="balanceDate" class="input mt-1.5">
                    @error('balanceDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Situação</label>
                    <select wire:model="balanceStatus" class="select mt-1.5 w-full">
                        <option value="open">Em aberto</option>
                        <option value="negotiating">Em negociação</option>
                    </select>
                </div>
            </div>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button></div>
        </form>
    </x-modal>

    {{-- Simulador de acordo --}}
    <x-modal wire-model="showAgreementSim" max-width="2xl">
        <div class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Simular acordo</h2>
                <button type="button" wire:click="$set('showAgreementSim', false)" class="btn-ghost px-2 py-1 text-xs">Fechar</button>
            </div>

            <p class="text-sm text-slate-600 dark:text-slate-300">
                Compare o que o credor propôs. O saldo hoje é <strong class="font-medium">{{ Money::format($debt->current_balance) }}</strong>. Em cada proposta, informe o valor total que você vai pagar e em quantas parcelas.
            </p>

            <form wire:submit="simulateAgreement" class="space-y-4">
                <div class="space-y-3">
                    @foreach ($agSimRows as $i => $linha)
                        <div class="grid gap-3 @sm:grid-cols-[1fr_1fr_7rem_auto] *:min-w-0" wire:key="ag-linha-{{ $i }}">
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Proposta</label>
                                <input type="text" wire:model="agSimRows.{{ $i }}.label" maxlength="40" class="input mt-1.5">
                                @error('agSimRows.'.$i.'.label') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Total a pagar</label>
                                <input type="text" wire:model="agSimRows.{{ $i }}.total" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 3000,00">
                                @error('agSimRows.'.$i.'.total') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parcelas</label>
                                <input type="number" wire:model="agSimRows.{{ $i }}.installments" min="1" max="480" class="input mt-1.5">
                                @error('agSimRows.'.$i.'.installments') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex items-end">
                                @if (count($agSimRows) > 1)
                                    <button type="button" wire:click="removeAgreementSimRow({{ $i }})" class="btn-ghost px-2 py-2 text-xs text-red-700 dark:text-red-400" aria-label="Remover proposta">Remover</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-3 @sm:grid-cols-2 *:min-w-0">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Juros por mês se não negociar <span class="font-normal text-slate-400">(%)</span></label>
                        <input type="text" wire:model="agSimGrowth" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 8">
                        <p class="mt-1 text-xs text-slate-400">Opcional: mostra quanto a dívida chegaria a ser em 12 meses.</p>
                        @error('agSimGrowth') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    @if (count($agSimRows) < 4)
                        <button type="button" wire:click="addAgreementSimRow" class="btn-ghost px-3 py-1.5 text-xs">Adicionar proposta</button>
                    @else
                        <span></span>
                    @endif
                    <button type="submit" class="btn-primary px-4 py-2 text-sm">Comparar</button>
                </div>
            </form>

            @if ($agSimResult !== [])
                <div class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-white/10">
                    <ul class="divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($agSimResult['proposals'] as $i => $p)
                            <li class="space-y-1 py-3 first:pt-0 last:pb-0" wire:key="ag-res-{{ $i }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                        {{ $p['label'] }}
                                        @if ($agSimResult['best_total'] === $i) <span class="badge ml-1 bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">menor custo</span> @endif
                                        @if ($agSimResult['lightest_month'] === $i && $agSimResult['best_total'] !== $i) <span class="badge ml-1 bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">menor parcela</span> @endif
                                    </p>
                                    <button type="button" wire:click="useAgreementProposal({{ $i }})" class="btn-secondary px-2.5 py-1 text-xs">Usar esta proposta</button>
                                </div>
                                <p class="text-sm tabular-nums text-slate-700 dark:text-slate-300">
                                    {{ Money::format($p['total']) }} em {{ $p['installments'] }} {{ $p['installments'] === 1 ? 'vez' : 'vezes' }}
                                    @if ($p['installments'] > 1) · {{ Money::format($p['monthly']) }} por mês @endif
                                </p>
                                <p class="text-xs {{ bccomp($p['discount'], '0', 2) > 0 ? 'text-accent-700 dark:text-accent-400' : 'text-red-700 dark:text-red-400' }}">
                                    @if (bccomp($p['discount'], '0', 2) > 0)
                                        Desconto de {{ Money::format($p['discount']) }} ({{ number_format($p['discount_percent'], 1, ',', '.') }}%) sobre o saldo de hoje.
                                    @elseif (bccomp($p['discount'], '0', 2) === 0)
                                        Sem desconto: paga o saldo de hoje.
                                    @else
                                        Paga {{ Money::format(bcsub('0', $p['discount'], 2)) }} a mais que o saldo de hoje.
                                    @endif
                                </p>
                                @if ($p['implicit_rate'] !== null)
                                    <p class="text-xs text-slate-400">Parcelar em vez de pagar "{{ $p['reference'] }}" custa cerca de {{ number_format($p['implicit_rate'], 2, ',', '.') }}% ao mês.</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($agSimResult['if_nothing'])
                        <p class="border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
                            Sem acordo, com {{ $agSimGrowth }}% ao mês, o saldo iria de {{ Money::format($agSimResult['balance']) }} para cerca de {{ Money::format($agSimResult['if_nothing']) }} em 12 meses.
                        </p>
                    @endif
                    <p class="text-xs text-slate-400">É uma comparação das propostas que você informou, sem considerar o que o dinheiro renderia em outro lugar. Confira tudo no contrato do acordo.</p>
                </div>
            @endif
        </div>
    </x-modal>
    {{-- Acordo --}}
    <x-modal wire-model="showAgreement" max-width="2xl">
        <form wire:submit="saveAgreement" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Registrar acordo</h2>
                <button type="button" wire:click="$set('showAgreement', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor negociado (total a pagar)</label>
                    <input type="text" wire:model="agreementTotal" inputmode="decimal" class="input mt-1.5">
                    @error('agreementTotal') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Em quantas parcelas</label>
                    <input type="number" wire:model="agreementInstallments" min="1" max="480" class="input mt-1.5">
                    @error('agreementInstallments') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vencimento da 1ª parcela</label>
                    <input type="date" wire:model="agreementFirstDue" class="input mt-1.5">
                    @error('agreementFirstDue') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta de pagamento <span class="font-normal text-slate-400">(opcional)</span></label>
                    <select wire:model="agreementAccountId" class="select mt-1.5 w-full">
                        <option value="">Nenhuma</option>
                        @foreach ($contas as $c)
                            <option value="{{ $c->id }}">{{ $c->bank_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria das parcelas</label>
                    <select wire:model.live="agreementCategoryId" class="select mt-1.5 w-full">
                        <option value="">Escolha</option>
                        @foreach ($categorias as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('agreementCategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Subcategoria</label>
                    <select wire:model="agreementSubcategoryId" class="select mt-1.5 w-full">
                        <option value="">Nenhuma</option>
                        @foreach ($categorias->firstWhere('id', $agreementCategoryId)?->subcategories ?? [] as $sub)
                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="text-xs text-slate-400">O saldo de hoje ({{ Money::format($debt->current_balance) }}) fica guardado e a diferença para o valor negociado é registrada como desconto obtido. As parcelas passam a contar no mês.</p>
            <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Registrar acordo</button></div>
        </form>
    </x-modal>

    {{-- Simulador --}}
    <x-modal wire-model="showSim" max-width="2xl">
        <div class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Simular</h2>
                <button type="button" wire:click="$set('showSim', false)" class="btn-ghost px-2 py-1 text-xs">Fechar</button>
            </div>

            <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs dark:border-white/10" role="group">
                <button type="button" wire:click="$set('simTab', 'prepay')" @class(['rounded-md px-3 py-1', 'bg-slate-100 font-medium text-slate-900 dark:bg-white/10 dark:text-white' => $simTab === 'prepay', 'text-slate-500 dark:text-slate-400' => $simTab !== 'prepay'])>Antecipar parte do saldo</button>
                <button type="button" wire:click="$set('simTab', 'port')" @class(['rounded-md px-3 py-1', 'bg-slate-100 font-medium text-slate-900 dark:bg-white/10 dark:text-white' => $simTab === 'port', 'text-slate-500 dark:text-slate-400' => $simTab !== 'port'])>Portabilidade</button>
            </div>

            <form wire:submit="simular" class="space-y-4">
                <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                    @if ($simTab === 'prepay')
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quanto você pagaria agora a mais</label>
                            <input type="text" wire:model="simExtra" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 20000,00">
                            @error('simExtra') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que prefere</label>
                            <select wire:model="simMode" class="select mt-1.5 w-full">
                                <option value="term">Manter a parcela e terminar antes</option>
                                <option value="installment">Manter o prazo e baixar a parcela</option>
                            </select>
                        </div>
                    @else
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Taxa de juros nova (%)</label>
                            <input type="text" wire:model="simRate" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 9,5">
                            @error('simRate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">A taxa é</label>
                            <select wire:model="simRateBasis" class="select mt-1.5 w-full">
                                @foreach ($basesTaxa as $valor => $rotulo)
                                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Prazo novo (parcelas)</label>
                            <input type="number" wire:model="simTerm" min="1" max="480" class="input mt-1.5">
                            @error('simTerm') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Seguro e taxas por parcela</label>
                            <input type="text" wire:model="simFees" inputmode="decimal" class="input mt-1.5">
                            @error('simFees') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="@sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Custo da operação <span class="font-normal text-slate-400">(tarifas, avaliação, IOF; opcional)</span></label>
                            <input type="text" wire:model="simCost" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 1500,00">
                            @error('simCost') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                <div class="flex justify-end"><button type="submit" class="btn-primary px-4 py-2 text-sm">Simular</button></div>
            </form>

            @if ($simResult !== [] && $simResult['tab'] === $simTab)
                @php $r = $simResult; $economiza = bccomp($r['saving'], '0', 2) > 0; @endphp
                <div class="rounded-xl border border-slate-200 p-4 text-sm dark:border-white/10">
                    @if ($r['tab'] === 'prepay' && $r['paid_off'])
                        <p class="text-slate-700 dark:text-slate-300">Esse valor quita o saldo de {{ Money::format($saldo) }}. Você deixa de pagar {{ Money::format($r['old_interest']) }} de juros dos contratos restantes.</p>
                    @else
                        <dl class="grid gap-3 sm:grid-cols-3">
                            <div><dt class="text-xs text-slate-400">Parcelas que faltam</dt><dd class="text-slate-800 dark:text-slate-200">{{ $r['old_term'] }} → <strong class="font-medium">{{ $r['term'] }}</strong></dd></div>
                            <div><dt class="text-xs text-slate-400">Primeira parcela</dt><dd class="text-slate-800 dark:text-slate-200">{{ Money::format($r['old_installment']) }} → <strong class="font-medium">{{ Money::format($r['first_installment']) }}</strong></dd></div>
                            <div><dt class="text-xs text-slate-400">Juros que faltam</dt><dd class="text-slate-800 dark:text-slate-200">{{ Money::format($r['old_interest']) }} → <strong class="font-medium">{{ Money::format($r['interest']) }}</strong></dd></div>
                        </dl>
                        <p class="mt-3 {{ $economiza ? 'text-accent-700 dark:text-accent-400' : 'text-red-700 dark:text-red-400' }}">
                            @if ($r['tab'] === 'prepay')
                                {{ $economiza ? 'Você economiza cerca de '.Money::format($r['saving']).' no total.' : 'Não há economia nessa simulação.' }}
                            @else
                                {{ $economiza ? 'A portabilidade economiza cerca de '.Money::format($r['saving']).' no total, já contando o custo da operação.' : 'Não compensa: você pagaria '.Money::format(bcsub('0', $r['saving'], 2)).' a mais no total.' }}
                            @endif
                        </p>
                    @endif
                    <p class="mt-2 text-xs text-slate-400">Estimativa pelo contrato cadastrado (taxa, saldo e parcelas em aberto). O banco pode cobrar tarifas, IOF ou correção que mudam o resultado: confirme com a proposta dele antes de decidir.</p>
                </div>
            @endif
        </div>
    </x-modal>
    {{-- Editar o contrato --}}
    <x-modal wire-model="showEdit" max-width="2xl">
        <form wire:submit="saveEdit" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Editar contrato</h2>
                <button type="button" wire:click="$set('showEdit', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>
            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="editName" maxlength="120" class="input mt-1.5">
                    @error('editName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Credor</label>
                    <input type="text" wire:model="editCreditor" maxlength="120" class="input mt-1.5">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">CPF/CNPJ do credor <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="text" wire:model="editCreditorDocument" maxlength="20" class="input mt-1.5" placeholder="Ex.: 00.000.000/0001-00">
                <p class="mt-1 text-xs text-slate-400">Aparece no relatório de imposto de renda.</p>
                    @error('editCreditorDocument') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data da contratação <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="date" wire:model="editContractedOn" class="input mt-1.5">
                    @error('editContractedOn') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                @unless ($semCategoria)
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria das parcelas</label>
                    <select wire:model.live="editCategoryId" class="select mt-1.5 w-full">
                        <option value="">Escolha</option>
                        @foreach ($categorias as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('editCategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Subcategoria</label>
                    <select wire:model="editSubcategoryId" class="select mt-1.5 w-full">
                        <option value="">Nenhuma</option>
                        @foreach ($categorias->firstWhere('id', $editCategoryId)?->subcategories ?? [] as $sub)
                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endunless
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta de pagamento</label>
                    <select wire:model="editAccountId" class="select mt-1.5 w-full">
                        <option value="">Nenhuma</option>
                        @foreach ($contas as $c)
                            <option value="{{ $c->id }}">{{ $c->bank_name }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($membros->count() > 1)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">De quem é</label>
                        <select wire:model="editMemberId" class="select mt-1.5 w-full">
                            <option value="">Do casal</option>
                            @foreach ($membros as $m)
                                <option value="{{ $m->id }}">{{ $m->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Observações</label>
                    <textarea wire:model="editNotes" rows="3" maxlength="2000" class="input mt-1.5"></textarea>
                </div>
            </div>
            <label class="inline-flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                <input type="checkbox" wire:model="editReclassify" class="rounded border-slate-300">
                Aplicar a nova categoria também às parcelas já pagas (por padrão, só valem as próximas)
            </label>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" wire:click="deleteDebt" wire:confirm="Apagar este contrato e o cronograma? As despesas já lançadas continuam no fluxo de caixa." class="btn-ghost px-3 py-1.5 text-xs text-red-700 dark:text-red-400">Apagar contrato</button>
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Salvar</button>
            </div>
        </form>
    </x-modal>
</div>
