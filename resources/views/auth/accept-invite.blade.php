<x-layouts.guest title="Criar acesso · Cerne">
    @if ($invite === null)
        <h1 class="text-lg font-semibold text-stone-900">Convite indisponível</h1>
        <p class="mt-2 text-sm text-stone-600">
            Este convite expirou ou já foi utilizado. Peça um novo ao seu consultor.
        </p>
        <a href="{{ route('login') }}" class="mt-6 inline-block text-sm text-brand-800 hover:underline">
            Ir para o login
        </a>
    @else
        <h1 class="text-lg font-semibold text-stone-900">Olá, {{ $invite->client_name }}</h1>
        <p class="mt-1 text-sm text-stone-500">
            {{ $invite->consultant->name }} convidou você para o Cerne. Defina sua senha para começar.
        </p>

        <form method="POST" action="{{ route('invite.store', ['token' => $token]) }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <label class="block text-sm font-medium text-stone-700">E-mail</label>
                <input
                    type="email"
                    value="{{ $invite->client_email }}"
                    autocomplete="username"
                    disabled
                    class="mt-1 block w-full rounded-lg border border-stone-200 bg-stone-50 px-3 py-2 text-sm text-stone-500"
                >
            </div>

            <div>
                <label for="profile_name" class="block text-sm font-medium text-stone-700">Nome do perfil</label>
                <input
                    id="profile_name"
                    name="profile_name"
                    type="text"
                    value="{{ old('profile_name', 'Finanças de '.explode(' ', trim($invite->client_name))[0]) }}"
                    required
                    class="input mt-1.5"
                >
                @error('profile_name')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-stone-700">Senha</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="input mt-1.5"
                >
                <p class="mt-1 text-xs text-stone-500">Mínimo de 8 caracteres, com letras e números.</p>
                @error('password')
                    <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-stone-700">Confirme a senha</label>
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
