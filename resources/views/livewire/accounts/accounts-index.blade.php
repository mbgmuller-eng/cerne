@use('App\Support\Money')

<div class="space-y-8">

    <div>
        <h1 class="font-display text-3xl font-semibold tracking-tight text-stone-900">Contas &amp; Cartões</h1>
        <p class="mt-1 text-sm text-stone-500">Saldos e faturas em aberto.</p>
    </div>

    {{-- Totais ------------------------------------------------------- --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="card p-5">
            <p class="eyebrow">Saldo em contas</p>
            <p class="figure mt-2 text-2xl font-medium text-brand-800">{{ Money::format($totalBalance) }}</p>
            <p class="mt-1 text-xs text-stone-400">Apenas contas incluídas no consolidado.</p>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Faturas em aberto</p>
            <p class="figure mt-2 text-2xl font-medium text-amber-700">{{ Money::format($totalCardDebt) }}</p>
            <p class="mt-1 text-xs text-stone-400">Soma das faturas ainda não pagas.</p>
        </div>
    </div>

    {{-- Contas -------------------------------------------------------- --}}
    <section>
        <h2 class="text-sm font-semibold text-stone-900">Contas bancárias</h2>

        @if ($accounts->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-10 text-center">
                <p class="text-sm text-stone-500">Nenhuma conta cadastrada.</p>
            </div>
        @else
            <ul class="mt-3 card divide-y divide-stone-100">
                @foreach ($accounts as $account)
                    <li class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="h-9 w-1.5 shrink-0 rounded-full" style="background: {{ $account->color_hex ?? '#0F766E' }}"></span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-stone-800">{{ $account->bank_name }}</p>
                                <p class="truncate text-xs text-stone-500">
                                    {{ $account->account_type->label() }}
                                    @if ($account->member) · {{ $account->member->name }} @endif
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            @if ($account->is_joint)
                                <span class="rounded-full bg-brand-100 px-2.5 py-0.5 text-xs text-brand-900">Conjunta</span>
                            @elseif (! $account->visible_to_partner)
                                <span class="rounded-full bg-stone-100 px-2.5 py-0.5 text-xs text-stone-600">Privada</span>
                            @endif

                            <span class="text-sm font-semibold tabular-nums {{ (float) $account->current_balance < 0 ? 'text-red-700' : 'text-stone-800' }}">
                                {{ Money::format($account->current_balance) }}
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Cartões ------------------------------------------------------- --}}
    <section>
        <h2 class="text-sm font-semibold text-stone-900">Cartões de crédito</h2>

        @if ($cards->isEmpty())
            <div class="mt-3 rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-10 text-center">
                <p class="text-sm text-stone-500">Nenhum cartão cadastrado.</p>
            </div>
        @else
            <ul class="mt-3 space-y-3">
                @foreach ($cards as $card)
                    @php $invoice = $currentInvoices->get($card->id); @endphp
                    <li class="card">
                        <div class="flex items-center justify-between gap-4 px-5 py-4">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="h-9 w-1.5 shrink-0 rounded-full" style="background: {{ $card->color_hex ?? '#B45309' }}"></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-stone-800">{{ $card->displayName() }}</p>
                                    <p class="truncate text-xs text-stone-500">
                                        {{ $card->card_brand->label() }} · fecha dia {{ $card->closing_day }} · vence dia {{ $card->due_day }}
                                        @if ($card->member) · {{ $card->member->name }} @endif
                                    </p>
                                </div>
                            </div>

                            <div class="shrink-0 text-right">
                                @if ($invoice)
                                    <p class="text-sm font-semibold tabular-nums text-stone-800">
                                        {{ Money::format($invoice->total_amount) }}
                                    </p>
                                    <p class="text-xs text-stone-500">
                                        {{ $invoice->status->label() }} · vence {{ $invoice->due_date->format('d/m') }}
                                    </p>
                                @else
                                    <p class="text-xs text-stone-400">Sem fatura aberta</p>
                                @endif
                            </div>
                        </div>

                        @if ($invoice)
                            <div class="border-t border-stone-100 px-5 py-3">
                                <a href="{{ route('invoices.show', $invoice) }}" class="text-sm text-brand-800 hover:underline">
                                    Ver fatura de {{ $invoice->competenceLabel() }} →
                                </a>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

</div>
