<x-layouts.guest title="Esqueci minha senha · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Esqueci minha senha</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Informe o e-mail da sua conta. Enviaremos um link para você escolher uma nova senha.</p>

    @if (session('status'))
        <p class="mt-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus class="input mt-1.5">
            @error('email')
                <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn-primary w-full py-2.5">Enviar link</button>
    </form>

    <p class="mt-8 text-center text-xs text-slate-500 dark:text-slate-400">
        <a href="{{ route('login') }}" class="text-brand-800 underline dark:text-brand-300">Voltar para entrar</a>
    </p>
</x-layouts.guest>
