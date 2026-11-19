<div class="space-y-6" data-analises="fluxo">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('cashflow.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">← Fluxo de caixa</a>
            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Análises</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Para onde vai o dinheiro, como evolui e em que ritmo.</p>
        </div>
        <button type="button" wire:click="baixarPdf" wire:loading.attr="disabled" wire:target="baixarPdf" class="btn-secondary px-3 py-1.5 text-sm" data-baixar-pdf>Baixar PDF</button>
    </div>

    <x-seletor-mes :rotulo="$referencia->translatedFormat('F \d\e Y')" :hoje="$ehMesAtual ? null : 'currentMonth'" class="w-full lg:w-auto" />

    @include('livewire.analytics.relatorio', ['relatorio' => $relatorio, 'abertas' => $abertas, 'categoriaAberta' => $categoriaId])

    <p class="text-xs text-slate-400">Os valores são os que você enxerga neste perfil: o que um cônjuge marcou como privado não entra nos totais.</p>
</div>
