@use('App\Support\Money')

<div class="space-y-6">

    <div>
        <a href="{{ route('accounts.index') }}" class="text-sm text-slate-500 dark:text-slate-400 hover:text-brand-800 dark:hover:text-brand-300">← Contas &amp; Cartões</a>
        <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">
            Fatura de {{ $invoice->competenceLabel() }}
        </h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $card->displayName() }} · {{ $card->bank_name }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="eyebrow">Total</p>
            <p class="figure mt-2 text-2xl font-medium text-slate-900 dark:text-white">{{ Money::format($invoice->total_amount) }}</p>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Fechamento</p>
            <p class="mt-1 text-lg font-medium text-slate-800 dark:text-slate-200">{{ $invoice->closing_date->format('d/m/Y') }}</p>
        </div>

        <div class="card p-5">
            <p class="eyebrow">Vencimento</p>
            <p class="mt-1 text-lg font-medium text-slate-800 dark:text-slate-200">{{ $invoice->due_date->format('d/m/Y') }}</p>
            <p class="mt-1 text-xs {{ $invoice->isPaid() ? 'text-brand-700 dark:text-brand-300' : 'text-amber-700 dark:text-amber-400' }}">
                {{ $invoice->status->label() }}
            </p>
        </div>
    </div>

    @if ($invoice->isPaid())
        <div class="rounded-lg border border-brand-200 bg-brand-50 dark:border-brand-500/30 dark:bg-brand-500/10 px-4 py-3 text-sm text-brand-900 dark:text-brand-100">
            Paga em {{ $invoice->paid_at->format('d/m/Y') }} — {{ Money::format($invoice->paid_amount) }}
            @if ($invoice->paidFromAccount)
                · debitado de {{ $invoice->paidFromAccount->bank_name }}
            @endif
        </div>
    @endif

    <section>
        <h2 class="text-sm font-semibold text-slate-900 dark:text-white">Lançamentos</h2>

        <div class="mt-3 rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-5 py-10 text-center">
            <p class="text-sm text-slate-500 dark:text-slate-400">Os lançamentos aparecem aqui quando o fluxo de caixa entrar.</p>
            <p class="mt-1 text-xs text-slate-400">Inclui as parcelas distribuídas nesta fatura.</p>
        </div>
    </section>

</div>
