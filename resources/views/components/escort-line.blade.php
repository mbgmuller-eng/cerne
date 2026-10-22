{{-- "Quem leva": quem vai levar a pessoa acompanhada à consulta. Qualquer participante se oferece ("Eu levo", inclusive o de leitura); só dono e cuidador de
     edição escolhem outra pessoa. As ações são do Livewire da agenda (takeEscort, releaseEscort, assignEscort). --}}
@props(['consulta', 'participantes', 'podeEscalar'])

@php($eu = auth()->id())

<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" wire:key="escort-{{ $consulta->id }}">
    <span class="text-slate-500 dark:text-slate-400">
        Quem leva:
        @if ($consulta->escort)
            <strong class="font-medium text-slate-700 dark:text-slate-300">{{ $consulta->escort_user_id === $eu ? 'você' : $consulta->escort->name }}</strong>
        @else
            ninguém ainda
        @endif
    </span>

    @if ($consulta->escort_user_id !== $eu)
        <button type="button" wire:click="takeEscort('{{ $consulta->id }}')" class="font-medium text-saude-800 hover:underline dark:text-saude-200">Eu levo</button>
    @else
        <button type="button" wire:click="releaseEscort('{{ $consulta->id }}')" class="text-slate-400 hover:underline dark:text-slate-500">Não posso mais</button>
    @endif

    @if ($podeEscalar && $participantes->count() > 1)
        <select data-write wire:change="assignEscort('{{ $consulta->id }}', $event.target.value)" class="input w-auto py-0.5 text-xs">
            <option value="">Escolher quem leva...</option>
            @foreach ($participantes as $participante)
                <option value="{{ $participante->id }}" @selected($consulta->escort_user_id === $participante->id)>{{ $participante->name }}</option>
            @endforeach
        </select>
    @endif
</div>
