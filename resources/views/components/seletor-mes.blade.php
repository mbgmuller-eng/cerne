@props(['rotulo', 'anterior' => 'previousMonth', 'proximo' => 'nextMonth', 'hoje' => null])

{{--
    Seletor de mês (‹ Novembro de 2026 ›). Chama os métodos do componente Livewire onde estiver (por padrão previousMonth e nextMonth). No celular
    fica grudado logo abaixo do cabeçalho do app enquanto a página rola, como o mês do cabeçalho dos apps de finanças: dá para trocar o mês de qualquer
    ponto da lista. `hoje` (opcional) é o método que volta ao mês atual: o rótulo vira um botão.
--}}
<div {{ $attributes->class(['sticky top-14 z-10 -mx-4 flex justify-center px-4 py-2 lg:static lg:z-auto lg:mx-0 lg:justify-start lg:px-0 lg:py-0']) }} data-seletor-mes>
    <div class="inline-flex items-center gap-1 rounded-full bg-white/95 p-1 shadow-card ring-1 ring-slate-200 backdrop-blur dark:bg-slate-800/95 dark:ring-white/10">
        <button type="button" wire:click="{{ $anterior }}" class="flex h-9 w-9 items-center justify-center rounded-full text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10" aria-label="Mês anterior">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L9.06 10l3.71 3.71a.75.75 0 11-1.06 1.06l-4.25-4.25a.75.75 0 010-1.06l4.25-4.25a.75.75 0 011.06 0z" clip-rule="evenodd"/></svg>
        </button>
        @if ($hoje)
            <button type="button" wire:click="{{ $hoje }}" class="min-w-[8.5rem] px-2 text-center text-sm font-medium text-slate-800 first-letter:uppercase dark:text-slate-100" title="Voltar ao mês atual">{{ $rotulo }}</button>
        @else
            <span class="min-w-[8.5rem] px-2 text-center text-sm font-medium text-slate-800 first-letter:uppercase dark:text-slate-100">{{ $rotulo }}</span>
        @endif
        <button type="button" wire:click="{{ $proximo }}" class="flex h-9 w-9 items-center justify-center rounded-full text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10" aria-label="Próximo mês">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L10.94 10 7.23 6.29a.75.75 0 111.06-1.06l4.25 4.25a.75.75 0 010 1.06l-4.25 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/></svg>
        </button>
    </div>
</div>
