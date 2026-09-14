@props(['period', 'options'])

{{--
    wire:model aqui aponta pro componente Livewire que INCLUI este
    componente Blade (ele não é um Livewire component próprio) — por
    isso funciona igual nas duas telas que o usam (InvestmentsIndex do
    cliente e PortfolioInvestments do consultor), cada uma com suas
    próprias growthPeriod/growthMonthA/growthMonthB vindas de
    FiltersInvestmentGrowth.
--}}
<div class="flex flex-wrap items-end gap-3">
    <div>
        <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Crescimento</label>
        <select wire:model.live="growthPeriod" class="select mt-1.5">
            @foreach ($options as $valor => $rotulo)
                <option value="{{ $valor }}">{{ $rotulo }}</option>
            @endforeach
        </select>
    </div>

    @if ($period === 'comparar')
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">De</label>
            <input type="month" wire:model.live="growthMonthA" class="input mt-1.5">
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 dark:text-slate-400">Até</label>
            <input type="month" wire:model.live="growthMonthB" class="input mt-1.5">
        </div>
    @endif
</div>
