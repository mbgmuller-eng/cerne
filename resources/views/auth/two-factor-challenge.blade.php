<x-layouts.guest title="Verificação em dois passos · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Verificação em dois passos</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Digite o código de 6 dígitos que aparece no seu aplicativo autenticador.</p>

    <form method="POST" action="{{ route('two-factor.store') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label for="code" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Código</label>
            <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus class="input mt-1.5" maxlength="32">
            <p class="mt-1 text-xs text-slate-400">Sem o aplicativo, use um dos códigos de recuperação que você guardou (cada um vale uma vez).</p>
            @error('code')
                <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-start gap-2.5 text-sm text-slate-600 dark:text-slate-400">
            <input name="trust" type="checkbox" value="1" class="mt-0.5 h-4 w-4 shrink-0 rounded border-slate-300 text-brand-700 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800" data-trust-browser>
            <span>
                Confiar neste navegador por {{ $dias }} dias
                <span class="mt-0.5 block text-xs text-slate-400">Neste navegador, o código não é pedido nos próximos {{ $dias }} dias. A senha continua sendo pedida. Não marque em computador de uso comum.</span>
            </span>
        </label>

        <button type="submit" class="btn-primary w-full py-2.5">Confirmar</button>
    </form>

    <p class="mt-8 text-center text-xs text-slate-500 dark:text-slate-400">
        <a href="{{ route('login') }}" class="text-brand-800 underline dark:text-brand-300">Voltar para entrar</a>
    </p>
</x-layouts.guest>
