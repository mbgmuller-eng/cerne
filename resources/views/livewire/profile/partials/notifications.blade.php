{{-- Notificações ---------------------------------------------------- --}}
<section class="card p-5">
    <p class="text-sm font-semibold text-slate-900 dark:text-white">Notificações</p>
    <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
        Avisos no sino do app estão sempre ativos. Escolha os outros canais para vencimento de conta, fatura de cartão, status de importação de PDF e, se você for consultor ou corretor, datas importantes da carteira:
    </p>

    <div class="mt-3 space-y-3">
        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input type="checkbox" wire:model.live="notifyEmail" class="rounded border-slate-300 dark:border-slate-600 text-brand-700 dark:text-brand-400 focus:ring-brand-500">
            E-mail
        </label>
        <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
            <input
                type="checkbox"
                wire:model.live="notifyPush"
                class="rounded border-slate-300 dark:border-slate-600 text-brand-700 dark:text-brand-400 focus:ring-brand-500"
                @change="if ($event.target.checked) { window.cerneSubscribeToPush?.() }"
            >
            Notificações push (neste navegador)
        </label>
    </div>
</section>
