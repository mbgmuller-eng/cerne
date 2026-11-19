<div class="space-y-6" data-analises="investimentos">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('investments.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">← Investimentos</a>
            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Análises</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Onde o seu patrimônio está concentrado. Para rentabilidade e proventos no tempo, veja o <a href="{{ route('investments.performance') }}" class="underline">Desempenho</a>.</p>
        </div>
        @if (count($relatorio['secoes']) > 0)
            <button type="button" wire:click="baixarPdf" wire:loading.attr="disabled" wire:target="baixarPdf" class="btn-secondary px-3 py-1.5 text-sm" data-baixar-pdf>Baixar PDF</button>
        @endif
    </div>

    @include('livewire.analytics.relatorio', ['relatorio' => $relatorio])

    <p class="text-xs text-slate-400">Valores em reais, inclusive moedas como BTC e USDT (cotação do dia). O que um cônjuge marcou como privado não entra nos totais.</p>
</div>
