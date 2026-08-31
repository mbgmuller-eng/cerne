<x-layouts.guest title="Criar acesso · Cerne">
    @if ($invite === null)
        <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Convite indisponível</h1>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
            Este convite expirou ou já foi utilizado. Peça um novo ao seu consultor.
        </p>
        <a href="{{ route('login') }}" class="mt-6 inline-block text-sm text-brand-800 dark:text-brand-300 hover:underline">
            Ir para o login
        </a>
    @else
        <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Olá, {{ $invite->client_name }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $invite->consultant->name }} convidou você para o Cerne. Defina sua senha para começar.
        </p>

        <form method="POST" action="{{ route('invite.store', ['token' => $token]) }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">E-mail</label>
                <input
                    type="email"
                    value="{{ $invite->client_email }}"
                    autocomplete="username"
                    disabled
                    class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-700 px-3 py-2 text-sm text-slate-500 dark:text-slate-400"
                >
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Senha</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="input mt-1.5"
                >
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Mínimo de 8 caracteres, com letras e números.</p>
                @error('password')
                    <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Confirme a senha</label>
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="input mt-1.5"
                >
            </div>

            <button
                type="submit"
                class="w-full btn-primary"
            >
                Criar meu acesso
            </button>
        </form>
    @endif
</x-layouts.guest>
