<x-layouts.guest title="Assinatura necessária · Cerne">
    <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Essa área precisa de assinatura</h1>
    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
        Este produto do Cerne não está incluído no seu acesso atual — nem direto, nem por um
        consultor/corretor vinculado. Assine o pacote certo para abrir esta tela.
    </p>

    <a href="{{ route('subscription.index') }}" class="mt-6 block w-full btn-primary text-center">
        Ver pacotes e assinar
    </a>

    <a href="{{ route('dashboard') }}" class="mt-3 block text-center text-sm text-brand-800 hover:underline dark:text-brand-300">
        Voltar
    </a>
</x-layouts.guest>
