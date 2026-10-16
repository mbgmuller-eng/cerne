@use('App\Support\Money')

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">Desempenho</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Patrimônio, rentabilidade contra o CDI, composição e proventos dos investimentos.</p>
        </div>
        <a href="{{ route('investments.index') }}" class="btn-ghost px-3 py-1.5 text-sm">Voltar aos investimentos</a>
    </div>

    <div class="card flex flex-wrap items-end gap-4 p-4">
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Período</label>
            <select wire:model.live="periodo" class="select mt-1.5">
                <option value="12">Últimos 12 meses</option>
                <option value="24">Últimos 24 meses</option>
                <option value="0">Todo o histórico</option>
            </select>
        </div>
        @if ($membros->count() > 1)
            <div>
                <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Pessoa</label>
                <select wire:model.live="pessoa" class="select mt-1.5">
                    <option value="todos">Todos juntos</option>
                    @foreach ($membros as $m)
                        <option value="{{ $m->id }}">{{ $m->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    @if (! $dados['has_data'])
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white/60 px-5 py-12 text-center dark:border-slate-600 dark:bg-slate-800/40">
            <p class="text-sm text-slate-600 dark:text-slate-300">Ainda não há dados de investimentos para mostrar aqui.</p>
        </div>
    @else
        @include('livewire.investments.partials.performance-charts', ['dados' => $dados, 'meses' => $meses])
    @endif
</div>
