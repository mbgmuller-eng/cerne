<x-layouts.guest title="Confirme seu e-mail · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Confirme seu e-mail</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
        Enviamos um link de confirmação para <strong>{{ auth()->user()->email }}</strong>.
        Clique nele para ativar sua conta e escolher seu pacote.
    </p>

    @if (session('status'))
        <p class="mt-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
        @csrf
        <button type="submit" class="btn-primary w-full py-2.5">Reenviar e-mail de confirmação</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <button type="submit" class="w-full text-center text-sm text-slate-500 underline dark:text-slate-400">Sair</button>
    </form>
</x-layouts.guest>
