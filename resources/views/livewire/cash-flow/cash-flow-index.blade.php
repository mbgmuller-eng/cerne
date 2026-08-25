@use('App\Support\Money')
@use('App\Enums\Necessity')

<div class="space-y-6">

    {{-- Cabeçalho e navegação de mês ---------------------------------- --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Fluxo de caixa</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400 first-letter:uppercase">{{ $periodLabel }}</p>
        </div>

        <div class="flex items-center gap-1">
            <button wire:click="previousMonth" class="btn-secondary px-3 py-1.5">←</button>
            <button wire:click="nextMonth" class="btn-secondary px-3 py-1.5">→</button>
        </div>
    </div>

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

                        <div class="shrink-0 text-right">
                            <p class="text-sm tabular-nums text-slate-800 dark:text-slate-200">{{ Money::format($despesa->amount) }}</p>
                            @if ($despesa->isInstallment())
                                <p class="text-xs text-slate-400">parcela {{ $despesa->installmentLabel() }}</p>
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
                        <p class="shrink-0 text-sm tabular-nums text-brand-800 dark:text-brand-300">{{ Money::format($receita->amount) }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>
