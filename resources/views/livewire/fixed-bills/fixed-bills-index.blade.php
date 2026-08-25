@use('App\Support\Money')
@use('App\Enums\FixedBillPaymentStatus')
@use('App\Enums\RecurringIncomeStatus')
@use('App\Enums\RecurrenceType')

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Contas fixas</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400 first-letter:uppercase">{{ $periodLabel }}</p>
        </div>

        <div class="flex items-center gap-1">
            <button wire:click="previousMonth" class="btn-secondary px-3 py-1.5">←</button>
            <button wire:click="nextMonth" class="btn-secondary px-3 py-1.5">→</button>
        </div>
    </div>

    {{-- Abas ------------------------------------------------------------ --}}
    <div class="flex items-center gap-1 border-b border-slate-200 dark:border-white/10">
        <button
            wire:click="setTab('despesas')"
            @class(['px-3 py-2 text-sm font-medium border-b-2 -mb-px', 'border-brand-700 text-brand-800 dark:border-brand-400 dark:text-brand-300' => $tab === 'despesas', 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => $tab !== 'despesas'])
        >
            Despesas fixas
        </button>
        <button
            wire:click="setTab('receitas')"
            @class(['px-3 py-2 text-sm font-medium border-b-2 -mb-px', 'border-brand-700 text-brand-800 dark:border-brand-400 dark:text-brand-300' => $tab === 'receitas', 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => $tab !== 'receitas'])
        >
            Receitas recorrentes
        </button>
    </div>

    {{-- Casal / cada membro — só quando há algo marcado como oculto ---- --}}
    @if ($showPrivacyTabs)
        <x-privacy-tabs :members="$privacyMembers" :view-as="$viewAs" />
    @endif

    {{-- Despesas fixas ---------------------------------------------------- --}}
    @if ($tab === 'despesas')
        <div class="flex justify-end">
            <button wire:click="toggleBillForm" class="btn-primary px-3 py-1.5">+ Conta fixa</button>
        </div>

        @if ($showBillForm)
            <form wire:submit="saveBill" class="card space-y-4 p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Nova conta fixa</h2>
                    <button type="button" wire:click="toggleBillForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                        <input type="text" wire:model="billName" class="input mt-1.5" placeholder="Ex.: Aluguel">
                        @error('billName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor {{ $billIsVariable ? '(estimado)' : '' }}</label>
                        <input type="number" step="0.01" min="0.01" wire:model="billAmount" class="input mt-1.5" placeholder="0,00">
                        @error('billAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Recorrência</label>
                        <select wire:model.live="billRecurrence" class="select mt-1.5 w-full">
                            @foreach (RecurrenceType::options() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($billRecurrence === 'weekly')
                        <div wire:key="bill-field-due-weekday">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dia da semana</label>
                            <select wire:model="billDueWeekday" class="select mt-1.5 w-full">
                                <option value="">Selecione</option>
                                <option value="0">Domingo</option>
                                <option value="1">Segunda</option>
                                <option value="2">Terça</option>
                                <option value="3">Quarta</option>
                                <option value="4">Quinta</option>
                                <option value="5">Sexta</option>
                                <option value="6">Sábado</option>
                            </select>
                            @error('billDueWeekday') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @else
                        @if ($billRecurrence === 'annual')
                            <div wire:key="bill-field-due-month">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Mês</label>
                                <select wire:model="billDueMonth" class="select mt-1.5 w-full">
                                    <option value="">Selecione</option>
                                    @foreach (['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'] as $i => $nomeMes)
                                        <option value="{{ $i + 1 }}">{{ $nomeMes }}</option>
                                    @endforeach
                                </select>
                                @error('billDueMonth') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div wire:key="bill-field-due-day">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dia do vencimento</label>
                            <input type="number" min="1" max="31" wire:model="billDueDay" class="input mt-1.5" placeholder="Ex.: 5">
                            @error('billDueDay') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
                        <select wire:model="billCategoryId" class="select mt-1.5 w-full">
                            <option value="">Selecione</option>
                            @foreach ($expenseCategories as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->name }}</option>
                            @endforeach
                        </select>
                        @error('billCategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
                        <select wire:model="billMemberId" class="select mt-1.5 w-full">
                            <option value="">Conjunta / não informado</option>
                            @foreach ($members as $membro)
                                <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta pra debitar (opcional)</label>
                        <select wire:model="billBankAccountId" class="select mt-1.5 w-full">
                            <option value="">Nenhuma — só lembrete</option>
                            @foreach ($bankAccounts as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->displayName() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2 pt-5">
                        <input type="checkbox" wire:model="billIsVariable" id="billIsVariable" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <label for="billIsVariable" class="text-sm text-slate-600 dark:text-slate-400">Valor variável (luz, água...)</label>
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Notas (opcional)</label>
                        <textarea wire:model="billNotes" rows="2" class="input mt-1.5"></textarea>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">Salvar conta fixa</button>
                </div>
            </form>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="card p-5">
                <p class="eyebrow">Total do mês</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($total) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Ainda a pagar</p>
                <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($outstanding) }}</p>
            </div>
        </div>

        @if ($payments->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    {{ $hasBills ? 'Nenhuma conta neste mês.' : 'Nenhuma conta fixa cadastrada.' }}
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    Contas fixas são as que se repetem — semanal, mensal ou anual: aluguel, internet, plano de saúde.
                </p>
            </div>
        @else
            <ul class="card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($payments as $pagamento)
                    @php $conta = $pagamento->fixedBill; @endphp
                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $conta->name }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                vence {{ $pagamento->due_date->format('d/m') }}
                                @if ($conta->category) · {{ $conta->category->name }} @endif
                                @if ($conta->member) · {{ $conta->member->name }} @endif
                                @if ($conta->recurrence !== RecurrenceType::Monthly) · {{ $conta->recurrence->label() }} @endif
                                @if ($conta->is_variable) · valor variável @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-3">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-brand-100 text-brand-900 dark:bg-brand-500/20 dark:text-brand-100' => $pagamento->status === FixedBillPaymentStatus::Paid,
                                'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $pagamento->status === FixedBillPaymentStatus::Pending,
                                'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300' => $pagamento->status === FixedBillPaymentStatus::Overdue,
                                'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300' => $pagamento->status === FixedBillPaymentStatus::Skipped,
                            ])>{{ $pagamento->status->label() }}</span>

                            @if ($pagamento->status->isOutstanding())
                                @if ($conta->is_variable)
                                    <div>
                                        <input
                                            wire:model="valorPago.{{ $pagamento->id }}"
                                            type="text"
                                            placeholder="{{ Money::format($conta->amount, false) }}"
                                            class="w-28 input py-1.5 tabular-nums"
                                        >
                                        @error('valorPago.'.$pagamento->id)
                                            <p class="mt-0.5 text-xs text-red-700 dark:text-red-400">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @else
                                    <span class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($conta->amount) }}</span>
                                @endif

                                <button wire:click="pay('{{ $pagamento->id }}')" class="btn-primary px-3 py-1.5">
                                    Pagar
                                </button>
                                <button wire:click="skip('{{ $pagamento->id }}')" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">
                                    Pular
                                </button>
                            @else
                                <span class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($pagamento->effectiveAmount()) }}</span>
                                @if ($pagamento->paid_at)
                                    <span class="text-xs text-slate-400">em {{ $pagamento->paid_at->format('d/m') }}</span>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    {{-- Receitas recorrentes ----------------------------------------------- --}}
    @if ($tab === 'receitas')
        <div class="flex justify-end">
            <button wire:click="toggleIncomeForm" class="btn-primary px-3 py-1.5">+ Receita recorrente</button>
        </div>

        @if ($showIncomeForm)
            <form wire:submit="saveIncome" class="card space-y-4 p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Nova receita recorrente</h2>
                    <button type="button" wire:click="toggleIncomeForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                        <input type="text" wire:model="incomeName" class="input mt-1.5" placeholder="Ex.: Salário">
                        @error('incomeName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor {{ $incomeIsVariable ? '(estimado)' : '' }}</label>
                        <input type="number" step="0.01" min="0.01" wire:model="incomeAmount" class="input mt-1.5" placeholder="0,00">
                        @error('incomeAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Recorrência</label>
                        <select wire:model.live="incomeRecurrence" class="select mt-1.5 w-full">
                            @foreach (RecurrenceType::options() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if ($incomeRecurrence === 'weekly')
                        <div wire:key="income-field-due-weekday">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dia da semana</label>
                            <select wire:model="incomeDueWeekday" class="select mt-1.5 w-full">
                                <option value="">Selecione</option>
                                <option value="0">Domingo</option>
                                <option value="1">Segunda</option>
                                <option value="2">Terça</option>
                                <option value="3">Quarta</option>
                                <option value="4">Quinta</option>
                                <option value="5">Sexta</option>
                                <option value="6">Sábado</option>
                            </select>
                            @error('incomeDueWeekday') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @else
                        @if ($incomeRecurrence === 'annual')
                            <div wire:key="income-field-due-month">
                                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Mês</label>
                                <select wire:model="incomeDueMonth" class="select mt-1.5 w-full">
                                    <option value="">Selecione</option>
                                    @foreach (['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'] as $i => $nomeMes)
                                        <option value="{{ $i + 1 }}">{{ $nomeMes }}</option>
                                    @endforeach
                                </select>
                                @error('incomeDueMonth') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        @endif
                        <div wire:key="income-field-due-day">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dia do recebimento</label>
                            <input type="number" min="1" max="31" wire:model="incomeDueDay" class="input mt-1.5" placeholder="Ex.: 5">
                            @error('incomeDueDay') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
                        <select wire:model="incomeCategoryId" class="select mt-1.5 w-full">
                            <option value="">Selecione</option>
                            @foreach ($incomeCategories as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->name }}</option>
                            @endforeach
                        </select>
                        @error('incomeCategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
                        <select wire:model="incomeMemberId" class="select mt-1.5 w-full">
                            <option value="">Conjunta / não informado</option>
                            @foreach ($members as $membro)
                                <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta pra creditar (opcional)</label>
                        <select wire:model="incomeBankAccountId" class="select mt-1.5 w-full">
                            <option value="">Nenhuma — só lembrete</option>
                            @foreach ($bankAccounts as $conta)
                                <option value="{{ $conta->id }}">{{ $conta->displayName() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex items-center gap-2 pt-5">
                        <input type="checkbox" wire:model="incomeIsVariable" id="incomeIsVariable" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <label for="incomeIsVariable" class="text-sm text-slate-600 dark:text-slate-400">Valor variável (comissão, freela...)</label>
                    </div>

                    <div class="sm:col-span-2 lg:col-span-3">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Notas (opcional)</label>
                        <textarea wire:model="incomeNotes" rows="2" class="input mt-1.5"></textarea>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">Salvar receita recorrente</button>
                </div>
            </form>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="card p-5">
                <p class="eyebrow">Total do mês</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($incomeTotal) }}</p>
            </div>
            <div class="card p-5">
                <p class="eyebrow">Ainda a receber</p>
                <p class="figure mt-2 text-2xl font-medium text-brand-700 dark:text-brand-300">{{ Money::format($incomeOutstanding) }}</p>
            </div>
        </div>

        @if ($incomeOccurrences->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-12 text-center">
                <p class="text-sm text-slate-600 dark:text-slate-300">
                    {{ $hasIncomes ? 'Nenhuma receita neste mês.' : 'Nenhuma receita recorrente cadastrada.' }}
                </p>
                <p class="mt-1 text-xs text-slate-400">
                    Receitas recorrentes são as que se repetem — semanal, mensal ou anual: salário, aluguel recebido.
                </p>
            </div>
        @else
            <ul class="card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($incomeOccurrences as $ocorrencia)
                    @php $receita = $ocorrencia->recurringIncome; @endphp
                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">{{ $receita->name }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                previsto {{ $ocorrencia->due_date->format('d/m') }}
                                @if ($receita->category) · {{ $receita->category->name }} @endif
                                @if ($receita->member) · {{ $receita->member->name }} @endif
                                @if ($receita->recurrence !== RecurrenceType::Monthly) · {{ $receita->recurrence->label() }} @endif
                                @if ($receita->is_variable) · valor variável @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-3">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                'bg-brand-100 text-brand-900 dark:bg-brand-500/20 dark:text-brand-100' => $ocorrencia->status === RecurringIncomeStatus::Received,
                                'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' => $ocorrencia->status === RecurringIncomeStatus::Pending,
                                'bg-red-100 text-red-900 dark:bg-red-500/15 dark:text-red-300' => $ocorrencia->status === RecurringIncomeStatus::Overdue,
                                'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-300' => $ocorrencia->status === RecurringIncomeStatus::Skipped,
                            ])>{{ $ocorrencia->status->label() }}</span>

                            @if ($ocorrencia->status->isOutstanding())
                                @if ($receita->is_variable)
                                    <div>
                                        <input
                                            wire:model="valorPago.{{ $ocorrencia->id }}"
                                            type="text"
                                            placeholder="{{ Money::format($receita->amount, false) }}"
                                            class="w-28 input py-1.5 tabular-nums"
                                        >
                                        @error('valorPago.'.$ocorrencia->id)
                                            <p class="mt-0.5 text-xs text-red-700 dark:text-red-400">{{ $message }}</p>
                                        @enderror
                                    </div>
                                @else
                                    <span class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($receita->amount) }}</span>
                                @endif

                                <button wire:click="receive('{{ $ocorrencia->id }}')" class="btn-primary px-3 py-1.5">
                                    Receber
                                </button>
                                <button wire:click="skipIncome('{{ $ocorrencia->id }}')" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">
                                    Pular
                                </button>
                            @else
                                <span class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($ocorrencia->effectiveAmount()) }}</span>
                                @if ($ocorrencia->received_at)
                                    <span class="text-xs text-slate-400">em {{ $ocorrencia->received_at->format('d/m') }}</span>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

</div>
