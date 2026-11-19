@props(['dado'])

{{--
    Seta e % contra o mês anterior ("↑ 12% vs. mês anterior"). A cor diz se a mudança é BOA (verde) ou ruim (vermelha), não se subiu: despesa subindo é
    ruim. Sem mês anterior para comparar, não mostra nada em vez de inventar um número.
--}}
@if (($dado['pct'] ?? null) !== null)
    <span {{ $attributes->class([
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $dado['bom'],
        'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' => ! $dado['bom'],
    ]) }} data-variacao>
        <svg class="h-3 w-3 {{ $dado['sobe'] ? '' : 'rotate-180' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 17a.75.75 0 01-.75-.75V5.56L5.28 9.53a.75.75 0 01-1.06-1.06l5.25-5.25a.75.75 0 011.06 0l5.25 5.25a.75.75 0 11-1.06 1.06l-3.97-3.97v10.69A.75.75 0 0110 17z" clip-rule="evenodd"/></svg>
        {{ number_format($dado['pct'], 1, ',', '.') }}% <span class="hidden font-normal opacity-80 sm:inline">vs. mês anterior</span>
    </span>
@endif
