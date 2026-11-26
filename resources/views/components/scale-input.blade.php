{{-- Nota de 0 a 10 em botões (esforço e dor). `clear` deixa desmarcar tocando de novo (campo opcional). --}}
@props(['model', 'value' => null, 'label', 'hint' => null, 'clear' => false])

<div>
    <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $label }}</p>
    <div class="mt-1.5 grid grid-cols-11 gap-1" role="group" aria-label="{{ $label }}">
        @foreach (range(0, 10) as $n)
            @php $marcado = $value !== null && $value !== '' && (int) $value === $n; @endphp
            <button type="button" wire:click="$set('{{ $model }}', {{ $clear && $marcado ? 'null' : $n }})" aria-pressed="{{ $marcado ? 'true' : 'false' }}"
                @class(['rounded-lg border py-1.5 text-xs font-semibold tabular-nums', 'border-saude-800 bg-saude-800 text-white dark:border-saude-200 dark:bg-saude-200 dark:text-slate-900' => $marcado, 'border-slate-200 text-slate-600 hover:border-slate-400 dark:border-white/10 dark:text-slate-300' => ! $marcado])>{{ $n }}</button>
        @endforeach
    </div>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>
    @endif
</div>
