<x-layouts.guest title="Entrar · Cerne">
    <h1 class="font-display text-2xl font-semibold text-stone-900">Entrar</h1>
    <p class="mt-1 text-sm text-stone-500">Acesse suas finanças.</p>

    <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-stone-700">E-mail</label>
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
                <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-stone-700">Senha</label>
            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
                class="input mt-1.5"
            >
            @error('password')
                <p class="mt-1.5 text-sm text-red-700">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2.5 text-sm text-stone-600">
            <input name="remember" type="checkbox" value="1" class="h-4 w-4 rounded border-stone-300 text-brand-700 focus:ring-brand-500">
            Manter conectado
        </label>

        <button type="submit" class="btn-primary w-full py-2.5">Entrar</button>
    </form>

    <p class="mt-8 text-center text-xs text-stone-500">
        O acesso ao Cerne nasce do convite do seu consultor.
    </p>
</x-layouts.guest>
