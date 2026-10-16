@use('App\Support\Money')

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $debt->kind->label() }}{{ $debt->creditor ? ' · '.$debt->creditor : '' }}</p>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $debt->name }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Parcelas em <strong class="font-medium">{{ $debt->category?->name }}{{ $debt->subcategory ? ' › '.$debt->subcategory->name : '' }}</strong>
                @if ($debt->status === \App\Enums\DebtStatus::PaidOff) · <span class="text-accent-700 dark:text-accent-400">quitada</span> @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('debts.index') }}" class="btn-ghost px-3 py-1.5 text-sm">Voltar</a>
            <button type="button" wire:click="openEdit" class="btn-secondary px-3 py-1.5 text-sm">Editar contrato</button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-5">
            <p class="eyebrow">{{ $debt->balanceLabel() }}</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($saldo) }}</p>
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

    <section class="card p-5">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Parcelas</h2>
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
                                    @else
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
