@use('App\Support\Money')

@php
    $grupos = [
        ['rotulo' => 'Financiamentos', 'contratos' => $financiamentos],
        ['rotulo' => 'Empréstimos', 'contratos' => $emprestimos],
        ['rotulo' => 'Consórcios', 'contratos' => $consorcios],
        ['rotulo' => 'Dívidas em aberto', 'contratos' => $emAberto],
    ];
    $temContratos = collect($grupos)->contains(fn ($g) => $g['contratos']->isNotEmpty());
    $temCartas = bccomp($resumo['consortium_credit'], '0', 2) > 0;
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Dívidas</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Financiamentos, empréstimos, consórcios e dívidas em aberto, com as parcelas ligadas às suas despesas.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('debts.tax') }}" class="btn-ghost px-3 py-1.5 text-sm">Imposto de renda</a>
            <button type="button" wire:click="$dispatch('open-consortium-import')" class="btn-secondary px-3 py-1.5 text-sm">Importar extrato do consórcio</button>
            <button type="button" wire:click="openForm" class="btn-secondary px-3 py-1.5 text-sm">Nova dívida</button>
        </div>
    </div>

    <div @class(['grid gap-4 sm:grid-cols-3', 'lg:grid-cols-4' => $temCartas])>
        <div class="card p-5">
            <p class="eyebrow">Total devedor</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($resumo['total']) }}</p>
            <p class="mt-1 text-xs text-slate-400">saldo devedor, total a pagar e dívidas em aberto; consórcio só depois de contemplado</p>
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
        @if ($temCartas)
            <div class="card p-5">
                <p class="eyebrow">Cartas de consórcio</p>
                <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($resumo['consortium_credit']) }}</p>
                <p class="mt-1 text-xs text-slate-400">ainda não contempladas; não entram no patrimônio</p>
            </div>
        @endif
    </div>

    <livewire:debts.consortium-import />

    @if (! $temContratos)
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma dívida cadastrada.</p>
            <p class="mt-1 text-xs text-slate-400">Cadastre um financiamento, empréstimo, consórcio ou dívida em aberto e acompanhe cada parcela, o saldo e o que já foi pago.</p>
        </div>
    @else
        @foreach ($grupos as $g)
            @if ($g['contratos']->isNotEmpty())
                <section class="card p-5">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $g['rotulo'] }}</h2>
                    <ul class="mt-2 divide-y divide-slate-100 dark:divide-white/10">
                        @foreach ($g['contratos'] as $d)
                            @php
                                $proxima = $proximas->get($d->id);
                                $juntando = $d->isSavingForCredit();
                            @endphp
                            <li wire:key="divida-{{ $d->id }}">
                                <a href="{{ route('debts.show', $d) }}" class="flex flex-wrap items-center justify-between gap-3 py-3 hover:bg-slate-50/60 dark:hover:bg-white/5">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-200">
                                            {{ $d->name }}
                                            @if ($d->status === \App\Enums\DebtStatus::PaidOff)
                                                <span class="badge ml-1 bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300">quitada</span>
                                            @elseif ($d->status->isUnscheduled())
                                                <span @class(['badge ml-1', 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-300' => $d->status === \App\Enums\DebtStatus::Open, 'bg-accent-50 text-accent-700 dark:bg-accent-500/15 dark:text-accent-300' => $d->status === \App\Enums\DebtStatus::Negotiating])>{{ $d->status->label() }}</span>
                                            @elseif ($d->isConsortium())
                                                <span class="badge ml-1 bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $juntando ? 'não contemplado' : 'contemplado' }}</span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ implode(' · ', array_filter([$d->creditor, $d->isConsortium() ? $d->consortium_type?->label() : null, $d->category ? $d->category->name.($d->subcategory ? ' › '.$d->subcategory->name : '') : null])) }}
                                        </p>
                                        @if ($d->term > 0)
                                            <p class="mt-0.5 text-xs text-slate-400">
                                                {{ $d->paid_count }} de {{ $d->term }} pagas{{ $d->skipped_count > 0 ? ' · '.$d->skipped_count.' '.($d->skipped_count === 1 ? 'pulada' : 'puladas') : '' }}
                                                @if ($proxima) · próxima: parcela {{ $proxima->number }}, {{ $proxima->due_date->format('d/m/Y') }}, {{ Money::format($proxima->amount) }} @endif
                                            </p>
                                        @endif
                                        @if ($d->isConsortium() && ($d->flex_percent || ($d->adjustment_index && $d->next_adjustment_on)))
                                            <p class="text-xs text-slate-400">
                                                {{ implode(' · ', array_filter([$d->flex_percent ? 'flex de '.$d->flex_percent.'%' : null, ($d->adjustment_index && $d->next_adjustment_on) ? 'próximo reajuste em '.$d->next_adjustment_on->format('d/m/Y') : null])) }}
                                            </p>
                                        @endif
                                    </div>
                                    <div class="shrink-0 text-right">
                                        @if ($juntando)
                                            <p class="figure text-sm text-slate-800 dark:text-slate-200">{{ Money::format($d->credit_amount) }}</p>
                                            <p class="text-xs text-slate-400">carta</p>
                                        @else
                                            <p class="figure text-sm text-slate-800 dark:text-slate-200">{{ Money::format($d->balance()) }}</p>
                                            <p class="text-xs text-slate-400">{{ strtolower($d->balanceLabel()) }}</p>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach
    @endif

    @if ($estrategia->count() >= 2)
        <section class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Qual quitar primeiro</h2>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        @if ($ordem === 'balance')
                            Pelo menor saldo: acaba com uma dívida mais rápido e libera a parcela dela no orçamento.
                        @else
                            Pela maior taxa de juros: é o que mais economiza em juros ao longo do tempo.
                        @endif
                        Antes de antecipar, veja se o banco cobra tarifa ou perde-se algum benefício.
                    </p>
                </div>
                <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-xs dark:border-white/10" role="group" aria-label="Ordem">
                    <button type="button" wire:click="$set('ordem', 'rate')" @class(['rounded-md px-2.5 py-1', 'bg-slate-100 font-medium text-slate-900 dark:bg-white/10 dark:text-white' => $ordem !== 'balance', 'text-slate-500 dark:text-slate-400' => $ordem === 'balance'])>Maior taxa</button>
                    <button type="button" wire:click="$set('ordem', 'balance')" @class(['rounded-md px-2.5 py-1', 'bg-slate-100 font-medium text-slate-900 dark:bg-white/10 dark:text-white' => $ordem === 'balance', 'text-slate-500 dark:text-slate-400' => $ordem !== 'balance'])>Menor saldo</button>
                </div>
            </div>

            <ol class="mt-3 divide-y divide-slate-100 dark:divide-white/10">
                @foreach ($estrategia as $i => $linha)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2.5" wire:key="estrategia-{{ $linha['debt']->id }}">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-medium text-slate-600 dark:bg-white/10 dark:text-slate-300">{{ $i + 1 }}</span>
                            <div class="min-w-0">
                                <a href="{{ route('debts.show', $linha['debt']) }}" class="truncate text-sm font-medium text-slate-800 hover:underline dark:text-slate-200">{{ $linha['debt']->name }}</a>
                                <p class="text-xs text-slate-400">
                                    @if ($linha['annual_percent'] !== null)
                                        {{ number_format($linha['annual_percent'], 1, ',', '.') }}% ao ano ({{ number_format((float) $linha['monthly_rate'] * 100, 2, ',', '.') }}% ao mês){{ $linha['rate_source'] === 'parcelas' ? ', calculada pelas parcelas' : '' }}
                                    @else
                                        taxa não informada: confira os juros cobrados
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="figure text-sm text-slate-800 dark:text-slate-200">{{ Money::format($linha['balance']) }}</p>
                            <p class="text-xs text-slate-400">{{ $linha['installment'] ? Money::format($linha['installment']).' por mês' : 'sem parcela' }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @if (count($projecao['labels']) >= 2)
        <section class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Como as dívidas caem</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Pelo cronograma de cada contrato, sem contar consórcio nem dívida sem acordo. O atrasado entra no mês atual.</p>

            <h3 class="mt-4 text-xs font-medium text-slate-500 dark:text-slate-400">Saldo devedor ao fim de cada mês</h3>
            <x-timeseries-chart class="mt-2 text-amber-700 dark:text-amber-400" :labels="$projecao['labels']" :series="[['valores' => $projecao['balance'], 'tipo' => 'area', 'rotulo' => 'Saldo devedor']]" formato="moeda" />

            <h3 class="mt-5 text-xs font-medium text-slate-500 dark:text-slate-400">Parcelas a pagar em cada mês</h3>
            <x-timeseries-chart class="mt-2 text-slate-400" :labels="array_slice($projecao['labels'], 0, 24)" :series="[['valores' => array_slice($projecao['installments'], 0, 24), 'tipo' => 'barra', 'classe' => 'text-accent-600 dark:text-accent-400', 'rotulo' => 'Parcelas']]" formato="moeda" />
            @if (count($projecao['labels']) > 24)
                <p class="mt-1 text-xs text-slate-400">Os próximos 24 meses; o saldo acima vai até o fim dos contratos.</p>
            @endif
        </section>
    @endif
    <x-modal wire-model="showForm" max-width="2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Nova dívida</h2>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost px-2 py-1 text-xs">Cancelar</button>
            </div>

            <div class="grid gap-4 @sm:grid-cols-2 *:min-w-0">
                <div class="@sm:col-span-2">
                    <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo</label>
                    <select wire:model.live="kind" class="select mt-1.5 w-full">
                        <option value="financing">Financiamento</option>
                        <option value="loan">Empréstimo</option>
                        <option value="consortium">Consórcio</option>
                        <option value="debt">Dívida em aberto (ainda sem pagamento)</option>
                    </select>
                </div>

                @if ($kind === 'consortium')
                    <div class="contents *:min-w-0" wire:key="campos-consorcio">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Tipo do consórcio</label>
                        <select wire:model="consortiumType" class="select mt-1.5 w-full">
                            @foreach ($tiposConsorcio as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        @error('consortiumType') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Administradora</label>
                        <input type="text" wire:model="creditor" maxlength="120" class="input mt-1.5" placeholder="Ex.: Ademicon">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="name" maxlength="120" class="input mt-1.5" placeholder="Ex.: Consórcio do carro">
                        @error('name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Grupo e cota <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="consortiumGroup" maxlength="40" class="input mt-1.5" placeholder="Ex.: 1234 / 56">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Carta (crédito)</label>
                        <input type="text" wire:model="creditAmount" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 100000,00">
                        @error('creditAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor da parcela hoje</label>
                        <input type="text" wire:model="installmentAmount" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 1180,00">
                        @error('installmentAmount') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Prazo (parcelas)</label>
                        <input type="number" wire:model="term" min="1" max="480" class="input mt-1.5">
                        @error('term') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Vencimento da 1ª parcela</label>
                        <input type="date" wire:model="firstDueDate" class="input mt-1.5">
                        @error('firstDueDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parcelas já pagas <span class="font-normal text-slate-400">(sem contar as puladas)</span></label>
                        <input type="number" wire:model="paidBefore" min="0" class="input mt-1.5">
                        @error('paidBefore') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parcelas puladas <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="skippedInstallments" class="input mt-1.5" placeholder="Ex.: 3, 12">
                        @error('skippedInstallments') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Parcela flex: quanto a parcela é menor até a contemplação</label>
                        <select wire:model="flexPercent" class="select mt-1.5 w-full">
                            <option value="">Não é flex</option>
                            @foreach ([10, 20, 30, 40, 50] as $pct)
                                <option value="{{ $pct }}">{{ $pct }}%</option>
                            @endforeach
                        </select>
                        @error('flexPercent') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Índice de reajuste</label>
                        <select wire:model.live="adjustmentIndex" class="select mt-1.5 w-full">
                            <option value="">Sem reajuste</option>
                            @foreach ($indices as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($adjustmentIndex !== '')
                        <div>
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Reajustar a cada (meses)</label>
                            <input type="number" wire:model="adjustmentEvery" min="1" max="60" class="input mt-1.5">
                            @error('adjustmentEvery') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="@sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Primeiro reajuste depois de (meses, a contar da 1ª parcela)</label>
                            <input type="number" wire:model="adjustmentFirstAfter" min="1" max="120" class="input mt-1.5">
                            @error('adjustmentFirstAfter') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Objetivo <span class="font-normal text-slate-400">(opcional): o progresso dele passa a ser pelas parcelas pagas</span></label>
                        <select wire:model.live="goalChoice" class="select mt-1.5 w-full">
                            <option value="">Nenhum</option>
                            @foreach ($objetivos as $obj)
                                <option value="{{ $obj->id }}">{{ $obj->name }}</option>
                            @endforeach
                            <option value="new">Criar um objetivo para este consórcio</option>
                        </select>
                    </div>
                    @if ($goalChoice === 'new')
                        <div class="@sm:col-span-2">
                            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Nome do objetivo</label>
                            <input type="text" wire:model="goalName" maxlength="120" class="input mt-1.5" placeholder="Ex.: Trocar de carro">
                            @error('goalName') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta de onde sai o pagamento <span class="font-normal text-slate-400">(opcional)</span></label>
                        <select wire:model="bankAccountId" class="select mt-1.5 w-full">
                            <option value="">Nenhuma</option>
                            @foreach ($contas as $c)
                                <option value="{{ $c->id }}">{{ $c->bank_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    </div>
                @elseif ($kind === 'debt')
                    <div class="contents *:min-w-0" wire:key="campos-divida-aberta">
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">O que é</label>
                        <input type="text" wire:model="name" maxlength="120" class="input mt-1.5" placeholder="Ex.: Cartão de crédito atrasado">
                        @error('name') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Credor <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="creditor" maxlength="120" class="input mt-1.5" placeholder="Banco, loja ou pessoa">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">CPF ou CNPJ do credor <span class="font-normal text-slate-400">(para o imposto de renda; opcional)</span></label>
                        <input type="text" wire:model="creditorDocument" maxlength="20" class="input mt-1.5" placeholder="Ex.: 00.000.000/0001-00">
                        @error('creditorDocument') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Situação</label>
                        <select wire:model="openStatus" class="select mt-1.5 w-full">
                            <option value="open">Em aberto</option>
                            <option value="negotiating">Em negociação</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Valor original <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="text" wire:model="principal" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 2900,00">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Saldo atual</label>
                        <input type="text" wire:model="currentBalance" inputmode="decimal" class="input mt-1.5" placeholder="Ex.: 4250,00">
                        @error('currentBalance') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Saldo informado em</label>
                        <input type="date" wire:model="balanceDate" class="input mt-1.5">
                        @error('balanceDate') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    </div>
                @else
                    <div class="contents *:min-w-0" wire:key="campos-{{ $kind }}">
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
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">CPF ou CNPJ do credor <span class="font-normal text-slate-400">(para o imposto de renda; opcional)</span></label>
                        <input type="text" wire:model="creditorDocument" maxlength="20" class="input mt-1.5" placeholder="Ex.: 00.000.000/0001-00">
                        @error('creditorDocument') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Data da contratação <span class="font-normal text-slate-400">(opcional)</span></label>
                        <input type="date" wire:model="contractedOn" class="input mt-1.5">
                        @error('contractedOn') <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
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
                    <div class="@sm:col-span-2">
                        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Conta de onde sai o pagamento <span class="font-normal text-slate-400">(opcional)</span></label>
                        <select wire:model="bankAccountId" class="select mt-1.5 w-full">
                            <option value="">Nenhuma</option>
                            @foreach ($contas as $c)
                                <option value="{{ $c->id }}">{{ $c->bank_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    </div>
                @endif

                @if ($membros->count() > 1)
                    <div class="@sm:col-span-2">
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

            @if ($kind === 'consortium')
                <p class="text-xs text-slate-400">Antes de ser contemplado, cada parcela paga entra no fluxo de caixa como investimento (Consórcio). O consórcio não conta como dívida nem como patrimônio: o que vale é a carta. Quando for contemplado, você informa o novo valor da parcela e ele passa a ser uma dívida.</p>
            @elseif ($kind === 'debt')
                <p class="text-xs text-slate-400">A dívida conta no total devedor, mas não gera parcelas nem despesas até você registrar um acordo.</p>
            @else
                <p class="text-xs text-slate-400">O cronograma é calculado pelo contrato e você pode ajustar o valor ou a data de cada parcela depois. Cada parcela paga vira uma despesa na categoria escolhida.</p>
            @endif

            <div class="flex justify-end">
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Cadastrar</button>
            </div>
        </form>
    </x-modal>
</div>
