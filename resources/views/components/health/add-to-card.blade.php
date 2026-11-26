{{-- "Adicionar à ficha": leva à Ficha de Saúde com o formulário aberto e a origem escolhida. Não grava nada: quem salva é a pessoa, na ficha. --}}
@props(['member', 'origin'])

<p {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400']) }} data-write data-adicionar-ficha>
    <span>Adicionar à ficha:</span>
    <a href="{{ route('health.card.index', ['novo' => 'problema', 'pessoa' => $member, 'origem' => $origin]) }}" class="font-medium text-saude-800 hover:underline dark:text-saude-200">problema de saúde</a>
    <a href="{{ route('health.card.index', ['novo' => 'limitacao', 'pessoa' => $member, 'origem' => $origin]) }}" class="font-medium text-saude-800 hover:underline dark:text-saude-200">limitação física</a>
</p>
