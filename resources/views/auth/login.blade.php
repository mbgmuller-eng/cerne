<x-layouts.guest title="Entrar · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Entrar</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Entre na sua conta.</p>

    @if (session('status'))
        <p class="mt-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/20">
            {{ session('status') }}
        </p>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300">E-mail</label>
            <input
                id="email"
                name="email"
                type="email"
                value="{{ old('email') }}"
                autocomplete="username"
                required
                autofocus
                class="input mt-1.5"
            >
            @error('email')
                <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Senha</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                class="input mt-1.5"
            >
            @error('password')
                <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2.5 text-sm text-slate-600 dark:text-slate-400">
            <input name="remember" type="checkbox" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
            Manter conectado
        </label>

        <button type="submit" class="btn-primary w-full py-2.5">Entrar</button>

        <p class="text-center text-sm">
            <a href="{{ route('password.request') }}" class="text-brand-800 underline dark:text-brand-300">Esqueci minha senha</a>
        </p>
    </form>

    <p class="mt-8 text-center text-xs text-slate-500 dark:text-slate-400">
        Ainda não tem conta? <a href="{{ route('register') }}" class="text-brand-800 underline dark:text-brand-300">Criar conta</a>
    </p>
</x-layouts.guest>
