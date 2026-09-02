@use('App\Support\Money')
@use('App\Enums\Necessity')
@use('App\Enums\InvoiceStatus')

<div class="space-y-6">

    {{-- Cabeçalho e navegação de mês ---------------------------------- --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Fluxo de caixa</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400 first-letter:uppercase">{{ $periodLabel }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="toggleIncomeForm" class="btn-secondary px-3 py-1.5">+ Receita</button>
            <button wire:click="toggleExpenseForm" class="btn-primary px-3 py-1.5">+ Despesa</button>
            <span class="mx-1 h-5 w-px bg-slate-200 dark:bg-white/10"></span>
            <button wire:click="previousMonth" class="btn-secondary px-3 py-1.5">←</button>
            <button wire:click="nextMonth" class="btn-secondary px-3 py-1.5">→</button>
        </div>
    </div>

    {{-- Casal / cada membro — só quando há algo marcado como oculto ---- --}}
    @if ($showPrivacyTabs)
        <x-privacy-tabs :members="$privacyMembers" :view-as="$viewAs" />
    @endif

    {{-- Aplicar categoria a lançamentos com mesma descrição e valor ---- --}}
    @if ($duplicatas)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
            <p>
                {{ $duplicatas['quantidade'] }} {{ $duplicatas['quantidade'] === 1 ? 'lançamento tem' : 'lançamentos têm' }}
                a mesma descrição e valor — aplicar essa categoria a eles também?
            </p>
            <div class="flex shrink-0 gap-2">
                <button wire:click="descartarDuplicatas" class="text-sm text-amber-700 hover:underline dark:text-amber-300">Não, só este</button>
                <button wire:click="aplicarCategoriaAosDuplicados" class="btn-primary px-3 py-1.5">Aplicar a todos</button>
            </div>
        </div>
    @endif

    {{-- Nova despesa ----------------------------------------------------- --}}
    <x-modal wire-model="showExpenseForm">
        <form wire:submit="saveExpense" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingExpenseId ? 'Editar despesa' : 'Nova despesa' }}</h2>
                <button type="button" wire:click="toggleExpenseForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Descrição</label>
                    <input type="text" wire:model="expenseDescription" class="input mt-1.5" placeholder="Ex.: Supermercado">
                    @error('expenseDescription') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor</label>
                    <input type="number" step="0.01" min="0.01" wire:model="expenseAmount" class="input mt-1.5" placeholder="0,00">
                    @error('expenseAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data</label>
                    <input type="date" wire:model="expenseDate" class="input mt-1.5">
                    @error('expenseDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Necessidade</label>
                    <select wire:model.live="expenseNecessity" class="select mt-1.5 w-full">
                        <option value="">Selecione</option>
                        @foreach (Necessity::options() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('expenseNecessity') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
                    <select wire:model.live="expenseCategoryId" class="select mt-1.5 w-full">
                        <option value="">Selecione</option>
                        @foreach ($expenseFormCategories as $categoria)
                            <option value="{{ $categoria->id }}">{{ $categoria->name }}</option>
                        @endforeach
                    </select>
                    @error('expenseCategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                @unless ($expenseNecessity === Necessity::Investment->value)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Subcategoria</label>
                        <select wire:model="expenseSubcategoryId" class="select mt-1.5 w-full" @if ($expenseCategoryId === '') disabled @endif>
                            <option value="">Selecione</option>
                            @foreach ($expenseSubcategories as $sub)
                                <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                            @endforeach
                        </select>
                        @error('expenseSubcategoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Ou crie uma subcategoria</label>
                        <input type="text" wire:model="expenseNewSubcategory" class="input mt-1.5" placeholder="Ex.: Terapia">
                    </div>
                @endunless

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
                    <select wire:model.live="expenseMemberId" class="select mt-1.5 w-full">
                        <option value="">Conjunta / não informado</option>
                        @foreach ($members as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($privacyMembers->count() >= 2 && $expenseMemberId !== '')
                    <div class="flex items-center gap-2 pt-5">
                        <input type="checkbox" wire:model="expenseIsPrivate" id="expenseIsPrivate" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <label for="expenseIsPrivate" class="text-sm text-slate-600 dark:text-slate-400">Ocultar do meu cônjuge</label>
                    </div>
                @endif

                @if ($editingExpenseId)
                    {{-- Editar não troca cartão/conta de lugar — é mudança
                         estrutural (fatura, parcelamento), não cabe aqui. --}}
                    @if ($expensePaymentMethod === 'cartao')
                        <div class="sm:col-span-2 lg:col-span-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-700 dark:text-slate-400">
                            Despesa de cartão — cartão e parcelamento não podem ser alterados por aqui.
                        </div>
                    @else
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta (opcional)</label>
                            <select wire:model="expenseBankAccountId" class="select mt-1.5 w-full">
                                <option value="">Sem débito em conta</option>
                                @foreach ($bankAccounts as $conta)
                                    <option value="{{ $conta->id }}">{{ $conta->displayName() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                @else
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pagamento</label>
                        <select wire:model.live="expensePaymentMethod" class="select mt-1.5 w-full">
                            <option value="outro">Conta / dinheiro</option>
                            <option value="cartao">Cartão de crédito</option>
                        </select>
                    </div>

                    @if ($expensePaymentMethod === 'cartao')
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Cartão</label>
                            <select wire:model="expenseCreditCardId" class="select mt-1.5 w-full">
                                <option value="">Selecione</option>
                                @foreach ($creditCards as $cartao)
                                    <option value="{{ $cartao->id }}">{{ $cartao->displayName() }}</option>
                                @endforeach
                            </select>
                            @error('expenseCreditCardId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parcelas</label>
                            <input type="number" min="1" max="{{ $maxInstallments }}" wire:model="expenseInstallments" class="input mt-1.5">
                            @error('expenseInstallments') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-slate-400">1x = à vista no cartão.</p>
                        </div>
                    @else
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta (opcional)</label>
                            <select wire:model="expenseBankAccountId" class="select mt-1.5 w-full">
                                <option value="">Sem débito em conta</option>
                                @foreach ($bankAccounts as $conta)
                                    <option value="{{ $conta->id }}">{{ $conta->displayName() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                @endif

                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Notas (opcional)</label>
                    <textarea wire:model="expenseNotes" rows="2" class="input mt-1.5"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">{{ $editingExpenseId ? 'Salvar alterações' : 'Salvar despesa' }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Nova receita ------------------------------------------------------ --}}
    <x-modal wire-model="showIncomeForm">
        <form wire:submit="saveIncome" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $editingIncomeId ? 'Editar receita' : 'Nova receita' }}</h2>
                <button type="button" wire:click="toggleIncomeForm" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Descrição (opcional)</label>
                    <input type="text" wire:model="incomeDescription" class="input mt-1.5" placeholder="Ex.: Salário de agosto">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor</label>
                    <input type="number" step="0.01" min="0.01" wire:model="incomeAmount" class="input mt-1.5" placeholder="0,00">
                    @error('incomeAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data de recebimento</label>
                    <input type="date" wire:model="incomeDate" class="input mt-1.5">
                    @error('incomeDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
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

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
                    <select wire:model.live="incomeMemberId" class="select mt-1.5 w-full">
                        <option value="">Conjunta / não informado</option>
                        @foreach ($members as $membro)
                            <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($privacyMembers->count() >= 2 && $incomeMemberId !== '')
                    <div class="flex items-center gap-2 pt-5">
                        <input type="checkbox" wire:model="incomeIsPrivate" id="incomeIsPrivate" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        <label for="incomeIsPrivate" class="text-sm text-slate-600 dark:text-slate-400">Ocultar do meu cônjuge</label>
                    </div>
                @endif

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta (opcional)</label>
                    <select wire:model="incomeBankAccountId" class="select mt-1.5 w-full">
                        <option value="">Sem crédito em conta</option>
                        @foreach ($bankAccounts as $conta)
                            <option value="{{ $conta->id }}">{{ $conta->displayName() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-center gap-2 pt-5">
                    <input type="checkbox" wire:model="incomeRecurring" id="incomeRecurring" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <label for="incomeRecurring" class="text-sm text-slate-600 dark:text-slate-400">Recorrente</label>
                </div>

                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Notas (opcional)</label>
                    <textarea wire:model="incomeNotes" rows="2" class="input mt-1.5"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="submit" class="btn-primary px-4 py-2" wire:loading.attr="disabled">{{ $editingIncomeId ? 'Salvar alterações' : 'Salvar receita' }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Totais -------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Receitas</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800 dark:text-brand-300">{{ Money::format($totalIncome) }}</p>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Despesas</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($totalExpense) }}</p>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Sobra do mês</p>
            <p class="figure mt-2 text-2xl font-medium {{ (float) $balance < 0 ? 'text-red-700 dark:text-red-400' : 'text-slate-900 dark:text-white' }}">
                {{ Money::format($balance) }}
            </p>
        </div>
    </div>

    {{-- Composição por necessidade ------------------------------------ --}}
    @if ((float) $totalExpense > 0)
        <div class="card p-5">
            <p class="eyebrow">Composição das despesas</p>

            <div class="mt-3 flex h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                @foreach (Necessity::cases() as $caso)
                    @php $pct = Money::percentageOf($byNecessity[$caso->value], $totalExpense); @endphp
                    @if ($pct > 0)
                        <div style="width: {{ $pct }}%; background: {{ $caso->color() }}" title="{{ $caso->label() }}"></div>
                    @endif
                @endforeach
            </div>

            <div class="mt-3 flex flex-wrap gap-4">
                @foreach (Necessity::cases() as $caso)
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $caso->color() }}"></span>
                        <span class="text-xs text-slate-600 dark:text-slate-400">
                            {{ $caso->label() }} · {{ Money::format($byNecessity[$caso->value]) }}
                            <span class="text-slate-400">
                                ({{ Money::percentageOf($byNecessity[$caso->value], $totalExpense) }}%)
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Filtros -------------------------------------------------------- --}}
    <div class="flex flex-wrap items-end gap-3 card p-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Necessidade</label>
            <select wire:model.live="necessity" class="select mt-1.5">
                <option value="">Todas</option>
                @foreach (Necessity::options() as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria</label>
            <select wire:model.live="categoryId" class="select mt-1.5">
                <option value="">Todas</option>
                @foreach ($categories as $categoria)
                    <option value="{{ $categoria->id }}">{{ $categoria->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Membro</label>
            <select wire:model.live="memberId" class="select mt-1.5">
                {{-- "Consolidado" = todos os membros que este usuário pode ver --}}
                <option value="">Consolidado</option>
                @foreach ($members as $membro)
                    <option value="{{ $membro->id }}">{{ $membro->name }}</option>
                @endforeach
            </select>
        </div>

        @if ($necessity || $categoryId || $memberId)
            <button wire:click="clearFilters" class="pb-1.5 text-sm text-slate-500 dark:text-slate-400 hover:text-brand-800 dark:text-brand-300">Limpar filtros</button>
        @endif
    </div>

    {{-- Despesas ------------------------------------------------------- --}}
    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Despesas <span class="font-normal text-slate-400">({{ $expenses->count() }})</span></h2>

        @if ($expenses->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-10 text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma despesa neste recorte.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($expenses as $despesa)
                    @php $faturaPaga = $despesa->isOnCredit() && $despesa->invoice?->status === InvoiceStatus::Paid; @endphp
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="h-8 w-1 shrink-0 rounded-full" style="background: {{ $despesa->necessity->color() }}"></span>
                            <div class="min-w-0">
                                <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $despesa->description }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                    {{ $despesa->expense_date->format('d/m') }}
                                    · {{ $despesa->category->name }}
                                    @if ($despesa->subcategory) › {{ $despesa->subcategory->name }} @endif
                                    @if ($despesa->member) · {{ $despesa->member->name }} @endif
                                    @if ($despesa->isOnCredit()) · {{ $despesa->creditCard->card_name }} @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <div class="text-right">
                                <p class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($despesa->amount) }}</p>
                                @if ($despesa->isInstallment())
                                    <p class="text-xs text-slate-400">parcela {{ $despesa->installmentLabel() }}</p>
                                @endif
                            </div>

                            @if ($faturaPaga)
                                <span class="text-xs text-slate-400" title="Fatura já paga — estorne o pagamento para editar ou excluir">
                                    <x-nav-icon name="lock" class="h-4 w-4" />
                                </span>
                            @elseif ($confirmingDeleteExpenseId === $despesa->id)
                                <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
                                <button wire:click="deleteExpense('{{ $despesa->id }}')" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
                                <button wire:click="cancelDeleteExpense" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
                            @else
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button type="button" @click="open = !open" class="btn-ghost px-2 py-1.5" aria-label="Mais ações">
                                        <x-nav-icon name="dots" class="h-4 w-4" />
                                    </button>
                                    <div x-show="open" x-transition x-cloak @click="open = false" class="absolute right-0 z-10 mt-1 w-32 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10">
                                        <button wire:click="editExpense('{{ $despesa->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                                        <button wire:click="confirmDeleteExpense('{{ $despesa->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Excluir</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Receitas ------------------------------------------------------- --}}
    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Receitas <span class="font-normal text-slate-400">({{ $incomes->count() }})</span></h2>

        @if ($incomes->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-10 text-center">
                <p class="text-sm text-slate-500 dark:text-slate-400">Nenhuma receita neste recorte.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($incomes as $receita)
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm text-slate-800 dark:text-slate-200">{{ $receita->description ?: $receita->category->name }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                {{ $receita->received_date->format('d/m') }}
                                · {{ $receita->category->name }}
                                @if ($receita->member) · {{ $receita->member->name }} @endif
                                @if ($receita->is_recurring) · recorrente @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <p class="text-sm tabular-nums text-brand-800 dark:text-brand-300">{{ Money::format($receita->amount) }}</p>

                            @if ($confirmingDeleteIncomeId === $receita->id)
                                <span class="text-xs text-slate-500 dark:text-slate-400">Confirma?</span>
                                <button wire:click="deleteIncome('{{ $receita->id }}')" class="text-sm font-medium text-red-700 hover:underline dark:text-red-400">Sim</button>
                                <button wire:click="cancelDeleteIncome" class="text-sm text-slate-400 hover:text-slate-700 dark:hover:text-slate-300">Não</button>
                            @else
                                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                    <button type="button" @click="open = !open" class="btn-ghost px-2 py-1.5" aria-label="Mais ações">
                                        <x-nav-icon name="dots" class="h-4 w-4" />
                                    </button>
                                    <div x-show="open" x-transition x-cloak @click="open = false" class="absolute right-0 z-10 mt-1 w-32 overflow-hidden rounded-xl bg-white py-1 shadow-card ring-1 ring-brand-950/5 dark:bg-slate-800 dark:ring-white/10">
                                        <button wire:click="editIncome('{{ $receita->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-700">Editar</button>
                                        <button wire:click="confirmDeleteIncome('{{ $receita->id }}')" type="button" class="block w-full px-3 py-2 text-left text-sm text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">Excluir</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>
