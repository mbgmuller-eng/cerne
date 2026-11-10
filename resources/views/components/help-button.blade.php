{{-- Botão "?" do menu: abre o tutorial da tela atual (o componente livewire tutorial.page-help escuta o evento). O layout só o mostra em tela que tem
     tutorial no catálogo (App\Support\Tutorials\TutorialCatalog), para nunca existir um botão sem conteúdo por trás. --}}
<button
    type="button"
    x-on:click="Livewire.dispatch('tutorial-open')"
    title="Como usar esta tela"
    aria-label="Como usar esta tela"
    data-help-button
    {{ $attributes->merge(['class' => 'btn-ghost px-2']) }}
>
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M9.5 9.25a2.5 2.5 0 1 1 3.6 2.25c-.7.35-1.1.9-1.1 1.75" />
        <path d="M12 17h.01" />
    </svg>
</button>
