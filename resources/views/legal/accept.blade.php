<x-layouts.guest title="Termos e Política de Privacidade · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Antes de continuar</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
        Atualizamos os <a href="{{ route('legal.terms') }}" target="_blank" class="text-brand-800 underline dark:text-brand-300">Termos de Uso</a> e a
        <a href="{{ route('legal.privacy') }}" target="_blank" class="text-brand-800 underline dark:text-brand-300">Política de Privacidade</a>
        (versão de {{ \Carbon\CarbonImmutable::parse(config('cerne.legal.terms_version'))->format('d/m/Y') }}). Eles explicam que dados tratamos, para quê, com quem
        compartilhamos e quais são os seus direitos. Leia e confirme para seguir usando o Cerne.
    </p>

    <form method="POST" action="{{ route('legal.accept.store') }}" class="mt-6 space-y-4">
        @csrf
        <label class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-300">
            <input type="checkbox" name="accept" value="1" class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>Li e aceito os Termos de Uso e a Política de Privacidade.</span>
        </label>
        @error('accept') <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p> @enderror

        <button type="submit" class="btn-primary w-full py-2.5">Continuar</button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-3">
        @csrf
        <button type="submit" class="w-full text-center text-sm text-slate-500 underline dark:text-slate-400">Prefiro sair</button>
    </form>
</x-layouts.guest>
