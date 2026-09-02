@use('App\Enums\Necessity')

<div class="space-y-6">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Regras de categorização</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Quando a descrição de um item importado contém o padrão, a categoria (e a conta fixa, se houver) já vêm preenchidas na revisão.
        </p>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-brand-200 bg-brand-50 dark:border-brand-500/30 dark:bg-brand-500/10 px-4 py-3 text-sm text-brand-900 dark:text-brand-100">
            {{ session('status') }}
        </div>
    @endif

    {{-- Aplicar regra a lançamentos já existentes ------------------------ --}}
    @if ($regraAplicavelExistentes)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <p>
                {{ $regraAplicavelExistentes['quantidade'] }}
                {{ $regraAplicavelExistentes['quantidade'] === 1 ? 'lançamento já existente bate' : 'lançamentos já existentes batem' }}
                com essa regra — aplicar a categorização a eles também, ou só nas próximas importações?
            </p>
            <div class="flex shrink-0 gap-2">
                <button wire:click="descartarAplicacaoAosExistentes" class="text-sm text-amber-700 hover:underline dark:text-amber-300">Só nas futuras</button>
                <button wire:click="aplicarRegraAosExistentes" class="btn-primary px-3 py-1.5">Aplicar aos já existentes</button>
            </div>
        </div>
    @endif

    {{-- Abas ------------------------------------------------------------ --}}
    <div class="flex items-center gap-1 border-b border-slate-200 dark:border-white/10">
        <button
            wire:click="setTab('despesas')"
            @class(['px-3 py-2 text-sm font-medium border-b-2 -mb-px', 'border-brand-700 text-brand-800 dark:border-brand-400 dark:text-brand-300' => $tab === 'despesas', 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => $tab !== 'despesas'])
        >
            Regras de despesa
        </button>
        <button
            wire:click="setTab('receitas')"
            @class(['px-3 py-2 text-sm font-medium border-b-2 -mb-px', 'border-brand-700 text-brand-800 dark:border-brand-400 dark:text-brand-300' => $tab === 'receitas', 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => $tab !== 'receitas'])
        >
            Regras de receita
        </button>
    </div>

    {{-- Regras de despesa --------------------------------------------------- --}}
    @if ($tab === 'despesas')
        <div class="flex justify-end">
            <button wire:click="toggleExpenseForm" class="btn-primary px-3 py-1.5">+ Regra</button>
        </div>

        <x-modal wire-model="showExpenseForm">
            <form wire:submit="saveExpenseRule" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExpenseRuleId ? 'Editar regra' : 'Nova regra de despesa' }}</h2>
                    <button type="button" wire:click="toggleExpenseForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                    <div class="@sm:col-span-2 @lg:col-span-3">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Descrição contém</label>
                        <input type="text" wire:model="expensePattern" class="input mt-1.5" placeholder="Ex.: ADRIANA">
                        @error('expensePattern') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        <p class="mt-1 text-xs text-slate-400">Sem diferenciar maiúscula/minúscula. Casa em qualquer parte da descrição do extrato.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Necessidade</label>
                        <select wire:model.live="expenseNecessity" class="select mt-1.5 w-full">
                            @foreach (Necessity::options() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
                        <select wire:model.live="expenseCategoryId" class="select mt-1.5 w-full">
                            <option value="">Selecione</option>
                            @foreach ($expenseCategories as $categoria)
                                <option value="{{ $categoria->id }}">{{ $categoria->name }}</option>
                            @endforeach
                        </select>
                        @error('expenseCategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    @unless ($expenseNecessity === Necessity::Investment->value)
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Subcategoria</label>
                            <select wire:model="expenseSubcategoryId" class="select mt-1.5 w-full">
                                <option value="">Selecione</option>
                                @foreach ($expenseSubcategories as $subcategoria)
                                    <option value="{{ $subcategoria->id }}">{{ $subcategoria->name }}</option>
                                @endforeach
                            </select>
                            @error('expenseSubcategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endunless

                    <div class="@sm:col-span-2 @lg:col-span-3">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vincular a uma conta fixa (opcional)</label>
                        <select wire:model="expenseFixedBillId" class="select mt-1.5 w-full">
                            <option value="">Nenhuma — só categoriza</option>
                            @foreach ($fixedBills as $contaFixa)
                                <option value="{{ $contaFixa->id }}">{{ $contaFixa->name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">
                            Quando o item bater com essa regra e a conta fixa tiver um vencimento pendente próximo da data, a importação dá baixa nela em vez de criar uma despesa solta.
                        </p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">
                        {{ $editingExpenseRuleId ? 'Salvar alterações' : 'Salvar regra' }}
                    </button>
                </div>
            </form>
        </x-modal>

        @if ($expenseRules->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-10 text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma regra de despesa cadastrada.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($expenseRules as $regra)
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">"{{ $regra->pattern }}"</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ $regra->category->name }}
                                @if ($regra->subcategory) › {{ $regra->subcategory->name }} @endif
                                · {{ $regra->necessity->label() }}
                                @if ($regra->fixedBill) · conta fixa: {{ $regra->fixedBill->name }} @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            @if ($confirmingDeleteExpenseRuleId === $regra->id)
                                <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
                                <button wire:click="deleteExpenseRule('{{ $regra->id }}')" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
                                <button wire:click="cancelDeleteExpenseRule" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
                            @else
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button type="button" @click="open = !open" class="btn-ghost px-2 py-1.5" aria-label="Mais ações">
                                        <x-nav-icon name="dots" class="h-4 w-4" />
                                    </button>
                                    <div
                                        x-show="open" x-transition x-cloak @click="open = false"
                                        class="absolute right-0 z-10 mt-1 w-36 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10"
                                    >
                                        <button wire:click="editExpenseRule('{{ $regra->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                                        <button wire:click="confirmDeleteExpenseRule('{{ $regra->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Excluir</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

    {{-- Regras de receita --------------------------------------------------- --}}
    @if ($tab === 'receitas')
        <div class="flex justify-end">
            <button wire:click="toggleIncomeForm" class="btn-primary px-3 py-1.5">+ Regra</button>
        </div>

        <x-modal wire-model="showIncomeForm">
            <form wire:submit="saveIncomeRule" class="space-y-4">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingIncomeRuleId ? 'Editar regra' : 'Nova regra de receita' }}</h2>
                    <button type="button" wire:click="toggleIncomeForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
                </div>

                <div class="grid gap-4 @sm:grid-cols-2 @lg:grid-cols-3">
                    <div class="@sm:col-span-2 @lg:col-span-3">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Descrição contém</label>
                        <input type="text" wire:model="incomePattern" class="input mt-1.5">
                        @error('incomePattern') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

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

                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vincular a uma receita recorrente (opcional)</label>
                        <select wire:model="incomeRecurringIncomeId" class="select mt-1.5 w-full">
                            <option value="">Nenhuma — só categoriza</option>
                            @foreach ($recurringIncomes as $receitaRecorrente)
                                <option value="{{ $receitaRecorrente->id }}">{{ $receitaRecorrente->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">
                        {{ $editingIncomeRuleId ? 'Salvar alterações' : 'Salvar regra' }}
                    </button>
                </div>
            </form>
        </x-modal>

        @if ($incomeRules->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-10 text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma regra de receita cadastrada.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($incomeRules as $regra)
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">"{{ $regra->pattern }}"</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ $regra->category->name }}
                                @if ($regra->recurringIncome) · receita recorrente: {{ $regra->recurringIncome->name }} @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            @if ($confirmingDeleteIncomeRuleId === $regra->id)
                                <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
                                <button wire:click="deleteIncomeRule('{{ $regra->id }}')" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
                                <button wire:click="cancelDeleteIncomeRule" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
                            @else
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button type="button" @click="open = !open" class="btn-ghost px-2 py-1.5" aria-label="Mais ações">
                                        <x-nav-icon name="dots" class="h-4 w-4" />
                                    </button>
                                    <div
                                        x-show="open" x-transition x-cloak @click="open = false"
                                        class="absolute right-0 z-10 mt-1 w-36 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10"
                                    >
                                        <button wire:click="editIncomeRule('{{ $regra->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                                        <button wire:click="confirmDeleteIncomeRule('{{ $regra->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Excluir</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif

</div>
