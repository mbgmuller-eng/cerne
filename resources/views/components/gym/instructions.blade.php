@props(['exercise'])

{{--
    Passo a passo do exercício, vindo do catálogo (a biblioteca pública traduzida). O exercício da pessoa não guarda ligação com o
    catálogo, só o nome, então a orientação é achada por ele. O estado aberto/fechado fica no Alpine para sobreviver aos
    re-renders do Livewire (a cada série registrada a tela é redesenhada).
--}}
@php $passos = \App\Models\GymExerciseCatalog::instructionsFor($exercise->name); @endphp

@if ($passos)
    <div x-data="{ open: false }" class="mt-2">
        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="text-xs font-medium text-saude-800 hover:underline dark:text-saude-200">
            <span x-text="open ? 'Ocultar como fazer' : 'Como fazer'">Como fazer</span>
        </button>
        <div x-show="open" x-cloak class="mt-2 rounded-xl bg-slate-50 p-3 dark:bg-white/5">
            <ol class="list-decimal space-y-1.5 pl-5 text-xs text-slate-600 dark:text-slate-300">
                @foreach ($passos as $passo)
                    <li>{{ $passo }}</li>
                @endforeach
            </ol>
            <p class="mt-2 text-xs text-slate-400">Orientação geral. Em caso de dor ou dúvida, procure um profissional de educação física.</p>
        </div>
    </div>
@endif
