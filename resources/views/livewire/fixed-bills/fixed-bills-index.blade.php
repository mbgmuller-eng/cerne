@use('App\Support\Money')
@use('App\Enums\FixedBillPaymentStatus')

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
                Contas fixas são as que se repetem todo mês: aluguel, internet, plano de saúde.
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
                                {{-- Conta variável: o valor previsto é só estimativa,
                                     então o real é obrigatório no pagamento. --}}
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

</div>
