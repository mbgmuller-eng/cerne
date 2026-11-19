<div class="space-y-6" data-analises="dividas">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('debts.index') }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">← Dívidas</a>
            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Análises</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Quanto de cada parcela é juro e quanto amortiza a dívida.</p>
        </div>
        @if ($escolhida)
            <button type="button" wire:click="baixarPdf" wire:loading.attr="disabled" wire:target="baixarPdf" class="btn-secondary px-3 py-1.5 text-sm" data-baixar-pdf>Baixar PDF</button>
        @endif
    </div>

    @if ($escolhida)
        @if ($dividas->count() > 1)
            <div class="card p-4">
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Dívida</label>
                <select wire:model.live="divida" class="select mt-1.5 w-full sm:w-auto sm:min-w-[18rem]">
                    @foreach ($dividas as $d)
                        <option value="{{ $d->id }}" @selected($d->id === $escolhida->id)>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @include('livewire.analytics.relatorio', ['relatorio' => $relatorio])

        <p class="text-xs text-slate-400">Os números vêm do cronograma do contrato, sem correção que o credor aplique por fora. Parcela pulada em consórcio não entra.</p>
    @else
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40" data-analise-vazia>
            <p class="text-sm text-slate-600 dark:text-slate-300">Nenhuma dívida com juros no cronograma.</p>
            <p class="mt-1 text-xs text-slate-400">Cadastre um financiamento ou empréstimo em Dívidas para ver quanto de cada parcela é juro.</p>
            <a href="{{ route('debts.index') }}" class="btn-secondary mt-4 inline-flex px-3 py-1.5 text-sm">Ir para Dívidas</a>
        </div>
    @endif
</div>
