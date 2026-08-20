@use('App\Support\Money')

<div class="space-y-6">

    <div>
        <a href="{{ route('accounts.index') }}" class="text-sm text-stone-500 hover:text-brand-800">← Contas &amp; Cartões</a>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight text-stone-900">
            Fatura de {{ $invoice->competenceLabel() }}
        </h1>
        <p class="mt-1 text-sm text-stone-500">{{ $card->displayName() }} · {{ $card->bank_name }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Total</p>
            <p class="figure mt-2 text-2xl font-medium text-stone-900">{{ Money::format($invoice->total_amount) }}</p>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Fechamento</p>
            <p class="mt-1 text-lg font-medium text-stone-800">{{ $invoice->closing_date->format('d/m/Y') }}</p>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Vencimento</p>
            <p class="mt-1 text-lg font-medium text-stone-800">{{ $invoice->due_date->format('d/m/Y') }}</p>
            <p class="mt-1 text-xs {{ $invoice->isPaid() ? 'text-brand-700' : 'text-amber-700' }}">
                {{ $invoice->status->label() }}
            </p>
        </div>
    </div>

    @if ($invoice->isPaid())
        <div class="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 text-sm text-brand-900">
            Paga em {{ $invoice->paid_at->format('d/m/Y') }} — {{ Money::format($invoice->paid_amount) }}
            @if ($invoice->paidFromAccount)
                · debitado de {{ $invoice->paidFromAccount->bank_name }}
            @endif
        </div>
    @endif

    <section>
        <h2 class="text-sm font-semibold text-stone-900">Lançamentos</h2>

        <div class="mt-3 rounded-2xl border border-dashed border-stone-300 bg-white/60 px-5 py-10 text-center">
            <p class="text-sm text-stone-500">Os lançamentos aparecem aqui quando o fluxo de caixa entrar.</p>
            <p class="mt-1 text-xs text-stone-400">Inclui as parcelas distribuídas nesta fatura.</p>
        </div>
    </section>

</div>
