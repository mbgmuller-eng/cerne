@use('App\Support\Money')

@php
    $grupos = [
        ['rotulo' => 'Financiamentos', 'contratos' => $financiamentos, 'vazio' => 'Nenhum financiamento cadastrado.'],
        ['rotulo' => 'Empréstimos', 'contratos' => $emprestimos, 'vazio' => 'Nenhum empréstimo cadastrado.'],
    ];
    $temContratos = $financiamentos->isNotEmpty() || $emprestimos->isNotEmpty();
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Dívidas</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Financiamentos e empréstimos, com as parcelas ligadas às suas despesas.</p>
        </div>
        <button type="button" wire:click="openForm" class="btn-secondary px-3 py-1.5 text-sm">Nova dívida</button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Total devedor</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($resumo['total']) }}</p>
            <p class="mt-1 text-xs text-slate-400">financiamentos pelo saldo devedor; parcelas informadas pelo total a pagar</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Parcelas deste mês</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700 dark:text-amber-400">{{ Money::format($resumo['month_total']) }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ Money::format($resumo['month_paid']) }} pagas · {{ Money::format($resumo['month_open']) }} a pagar</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Comprometido da renda</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ $comprometido !== null ? number_format($comprometido, 0, ',', '.').'%' : '—' }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ $comprometido !== null ? 'das receitas lançadas no mês' : 'sem receitas lançadas neste mês' }}</p>
        </div>
    </div>

    @if (! $temContratos)
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma dívida cadastrada.</p>
            <p class="mt-1 text-xs text-slate-400">Cadastre um financiamento ou empréstimo e acompanhe cada parcela, o saldo e o que já foi pago.</p>
        </div>
    @else
        @foreach ($grupos as $g)
            @if ($g['contratos']->isNotEmpty())
                <section class="card p-5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $g['rotulo'] }}</h2>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($g['contratos'] as $d)
                            @php $proxima = $d->installments->first(); @endphp
                            <li wire:key="divida-{{ $d->id }}">
                                <a href="{{ route('debts.show', $d) }}" class="flex flex-wrap items-center justify-between gap-3 py-3 hover:bg-slate-50/60 dark:hover:bg-white/5">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">
                                            {{ $d->name }}
                                            @if ($d->status === \App\Enums\DebtStatus::PaidOff)
                                                <span class="badge ml-1 bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">quitada</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ $d->creditor ? $d->creditor.' · ' : '' }}{{ $d->category?->name }}{{ $d->subcategory ? ' › '.$d->subcategory->name : '' }}
                                        </p>
                                        @if ($proxima)
                                            <p class="mt-0.5 text-xs text-slate-400">Parcela {{ $proxima->number }} de {{ $d->term }} · {{ $proxima->due_date->format('d/m/Y') }} · {{ Money::format($proxima->amount) }}</p>
                                        @endif
                                    </div>
                                    <div class="shrink-0 text-right">
                                        <p class="figure text-sm text-slate-800 dark:text-slate-200">{{ Money::format($d->balance()) }}</p>
                                        <p class="text-xs text-slate-400">{{ strtolower($d->balanceLabel()) }}</p>
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach
    @endif

    <x-modal wire-model="showForm" max-width="2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Nova dívida</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                    <select wire:model.live="kind" class="select mt-1.5 w-full">
                        <option value="financing">Financiamento</option>
                        <option value="loan">Empréstimo</option>
                    </select>
                </div>
                @if ($kind === 'financing')
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que foi financiado</label>
                        <select wire:model.live="asset" class="select mt-1.5 w-full">
                            @foreach ($bens as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome</label>
                    <input type="text" wire:model="name" maxlength="120" class="input mt-1.5" placeholder="Ex.: Apartamento, Carro, Empréstimo pessoal">
                    @error('name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Credor <span class="font-normal text-slate-400">(opcional)</span></label>
                    <input type="text" wire:model="creditor" maxlength="120" class="input mt-1.5" placeholder="Banco ou financeira">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Como as parcelas são calculadas</label>
                    <select wire:model.live="amortization" class="select mt-1.5 w-full">
                        @foreach ($sistemas as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($amortization !== 'fixed')
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor financiado</label>
                        <input type="text" wire:model="principal" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 360000,00">
                        @error('principal') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Taxa de juros (%)</label>
                        <input type="text" wire:model="rate" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 10,5">
                        @error('rate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">A taxa é</label>
                        <select wire:model="rateBasis" class="select mt-1.5 w-full">
                            @foreach ($bases as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Seguro e taxas fixas por parcela <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="monthlyFees" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 85,00">
                    </div>
                @else
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor da parcela</label>
                        <input type="text" wire:model="installmentAmount" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 650,00">
                        @error('installmentAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor tomado <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="principal" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 12000,00">
                    </div>
                @endif

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Quantidade de parcelas</label>
                    <input type="number" wire:model="term" min="1" max="480" class="input mt-1.5">
                    @error('term') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vencimento da 1ª parcela</label>
                    <input type="date" wire:model="firstDueDate" class="input mt-1.5">
                    @error('firstDueDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parcelas já pagas antes de usar o Cerne</label>
                    <input type="number" wire:model="paidBefore" min="0" class="input mt-1.5">
                    @error('paidBefore') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Categoria das parcelas</label>
                    <select wire:model.live="categoryId" class="select mt-1.5 w-full">
                        <option value="">Escolha</option>
                        @foreach ($categorias as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('categoryId') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Subcategoria</label>
                    <select wire:model="subcategoryId" class="select mt-1.5 w-full">
                        <option value="">Nenhuma</option>
                        @foreach ($categorias->firstWhere('id', $categoryId)?->subcategories ?? [] as $sub)
                            <option value="{{ $sub->id }}">{{ $sub->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta de onde sai o pagamento <span class="font-normal text-slate-400">(opcional)</span></label>
                    <select wire:model="bankAccountId" class="select mt-1.5 w-full">
                        <option value="">Nenhuma</option>
                        @foreach ($contas as $c)
                            <option value="{{ $c->id }}">{{ $c->bank_name }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($membros->count() > 1)
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">De quem é</label>
                        <select wire:model="memberId" class="select mt-1.5 w-full">
                            <option value="">Do casal</option>
                            @foreach ($membros as $m)
                                <option value="{{ $m->id }}">{{ $m->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>

            <p class="text-xs text-slate-400">O cronograma é calculado pelo contrato e você pode ajustar o valor ou a data de cada parcela depois. Cada parcela paga vira uma despesa na categoria escolhida.</p>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Cadastrar e ver o cronograma</button>
            </div>
        </form>
    </x-modal>
</div>
