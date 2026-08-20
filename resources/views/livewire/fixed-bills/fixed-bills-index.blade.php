@use('App\Support\Money')
@use('App\Enums\FixedBillPaymentStatus')

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-900">Contas fixas</h1>
            <p class="mt-1 text-sm text-stone-500 first-letter:uppercase">{{ $periodLabel }}</p>
        </div>

        <div class="flex items-center gap-1">
            <button wire:click="previousMonth" class="btn-secondary px-3 py-1.5">←</button>
            <button wire:click="nextMonth" class="btn-secondary px-3 py-1.5">→</button>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card p-5">
            <p class="eyebrow">Total do mês</p>
            <p class="figure mt-2 text-2xl font-medium text-stone-900">{{ Money::format($total) }}</p>
        </div>
        <div class="card p-5">
            <p class="eyebrow">Ainda a pagar</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700">{{ Money::format($outstanding) }}</p>
        </div>
    </div>

    @if ($payments->isEmpty())
        <div class="rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-12 text-center">
            <p class="text-sm text-stone-600">
                {{ $hasBills ? 'Nenhuma conta neste mês.' : 'Nenhuma conta fixa cadastrada.' }}
            </p>
            <p class="mt-1 text-xs text-stone-400">
                Contas fixas são as que se repetem todo mês: aluguel, internet, plano de saúde.
            </p>
        </div>
    @else
        <ul class="card divide-y divide-stone-100">
            @foreach ($payments as $pagamento)
                @php $conta = $pagamento->fixedBill; @endphp
                <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-stone-800">{{ $conta->name }}</p>
                        <p class="truncate text-xs text-stone-500">
                            vence {{ $pagamento->due_date->format('d/m') }}
                            @if ($conta->category) · {{ $conta->category->name }} @endif
                            @if ($conta->member) · {{ $conta->member->name }} @endif
                            @if ($conta->is_variable) · valor variável @endif
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-3">
                        <span @class([
                            'rounded-full px-2.5 py-0.5 text-xs font-medium',
                            'bg-brand-100 text-brand-900' => $pagamento->status === FixedBillPaymentStatus::Paid,
                            'bg-stone-100 text-stone-600' => $pagamento->status === FixedBillPaymentStatus::Pending,
                            'bg-red-100 text-red-900' => $pagamento->status === FixedBillPaymentStatus::Overdue,
                            'bg-amber-100 text-amber-900' => $pagamento->status === FixedBillPaymentStatus::Skipped,
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
                                        <p class="mt-0.5 text-xs text-red-700">{{ $message }}</p>
                                    @enderror
                                </div>
                            @else
                                <span class="text-sm tabular-nums text-stone-800">{{ Money::format($conta->amount) }}</span>
                            @endif

                            <button wire:click="pay('{{ $pagamento->id }}')" class="btn-primary px-3 py-1.5">
                                Pagar
                            </button>
                            <button wire:click="skip('{{ $pagamento->id }}')" class="text-sm text-stone-400 hover:text-stone-700">
                                Pular
                            </button>
                        @else
                            <span class="text-sm tabular-nums text-stone-800">{{ Money::format($pagamento->effectiveAmount()) }}</span>
                            @if ($pagamento->paid_at)
                                <span class="text-xs text-stone-400">em {{ $pagamento->paid_at->format('d/m') }}</span>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

</div>
