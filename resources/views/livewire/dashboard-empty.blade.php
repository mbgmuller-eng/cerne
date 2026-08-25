<div class="rounded-2xl border border-dashed border-slate-300 dark:border-slate-600 bg-white/60 dark:bg-slate-800/40 px-6 py-12 text-center">
    <p class="text-sm text-slate-600 dark:text-slate-300">Nenhum perfil financeiro ativo.</p>

    @if ($isConsultant)
        <a href="{{ route('consultant.clients') }}" class="mt-2 inline-block text-sm text-brand-800 dark:text-brand-300 hover:underline">
            Escolher um cliente
        </a>
    @endif
</div>
