<x-layouts.guest title="Nova senha · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Escolha uma nova senha</h1>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Digite o e-mail da sua conta e a nova senha. Ao salvar, você sai de todos os aparelhos em que estava conectado.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="block text-sm font-medium text-slate-700 dark:text-slate-300">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus class="input mt-1.5">
            @error('email')
                <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Nova senha</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required class="input mt-1.5">
            <p class="mt-1 text-xs text-slate-400">Pelo menos 8 caracteres, com letras e números.</p>
            @error('password')
                <p class="mt-1.5 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Repita a nova senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input mt-1.5">
        </div>

        <button type="submit" class="btn-primary w-full py-2.5">Salvar nova senha</button>
    </form>
</x-layouts.guest>
