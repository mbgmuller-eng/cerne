<section class="card p-5">
    <p class="text-sm font-semibold text-slate-900 dark:text-white">Tutoriais</p>
    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
        Cada tela mostra uma vez um aviso de primeira visita, e o botão <span class="font-semibold">?</span> do menu abre o passo a passo quando você quiser.
        Se dispensou os avisos e quer vê-los de novo, use o botão abaixo.
    </p>
    <div class="mt-3 flex flex-wrap items-center gap-3">
        <button type="button" wire:click="showAgain" class="btn-secondary px-3 py-1.5 text-xs">Mostrar os avisos de novo</button>
        @if ($done)
            <span class="text-xs text-brand-700 dark:text-brand-300">Pronto. Os avisos voltam a aparecer em cada tela.</span>
        @endif
    </div>
</section>
