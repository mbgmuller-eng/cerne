{{-- Escala de dor: 0 é sem dor e 10 a pior. `$modelo` é a propriedade do componente e `$valor` o escolhido. --}}
<div class="mt-1.5 flex flex-wrap gap-1" role="group" aria-label="Nota de dor de 0 a 10">
    @for ($n = 0; $n <= 10; $n++)
        <button type="button" wire:click="$set('{{ $modelo }}', {{ $n }})" aria-pressed="{{ $valor === $n ? 'true' : 'false' }}"
            @class([
                'h-9 w-9 rounded-lg text-sm font-semibold transition',
                'bg-amber-600 text-white' => $valor === $n,
                'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-white/10 dark:text-slate-200' => $valor !== $n,
            ])>{{ $n }}</button>
    @endfor
</div>
<p class="mt-1 text-xs text-slate-400">0 é sem dor e 10 é a pior dor.</p>
