<x-layouts.guest title="Verificação em dois passos · Cerne">
    <h1 class="font-display text-2xl font-semibold text-slate-900 dark:text-white">Proteja a sua conta</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
        Para a sua função, o Cerne exige a verificação em dois passos: você cuida de dados de outras pessoas, e a senha sozinha não basta.
        Configure agora (leva um minuto) para seguir usando o app.
    </p>

    <div class="mt-6">
        <livewire:profile.two-factor-settings :standalone="true" />
    </div>

    {{-- Sem a configuração concluída, o RequiresTwoFactor devolve a pessoa para esta tela. --}}
    <a href="{{ route('dashboard') }}" class="btn-primary mt-6 block w-full py-2.5 text-center">Já guardei os códigos: continuar</a>

    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="w-full text-center text-sm text-slate-500 underline dark:text-slate-400">Sair</button>
    </form>
</x-layouts.guest>
