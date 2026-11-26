{{-- Cabeçalho e navegação da área Atividades. A faixa de abas rola por dentro no celular: nunca alarga a página. --}}
@props(['title', 'subtitle' => null])

@php
    $abas = [
        ['health.activities.index', 'Minha semana', ['health.activities.index']],
        ['health.activities.log', 'Registrar', ['health.activities.log']],
        ['health.activities.review', 'Revisar treino', ['health.activities.review', 'health.activities.consultation']],
        ['health.gym.index', 'Academia', ['health.gym.*']],
        ['health.activities.running', 'Corrida', ['health.activities.running']],
        ['health.activities.progress', 'Evolução', ['health.activities.progress']],
        ['health.activities.profile', 'Meu perfil', ['health.activities.profile']],
    ];
@endphp

<div>
    <p class="eyebrow">Atividades · só você vê</p>
    <div class="mt-1 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="font-display text-3xl font-semibold tracking-tight text-slate-900 dark:text-white">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">{{ $slot }}</div>
    </div>
    {{-- No celular a faixa rola até a aba aberta (só ela rola: a página não se mexe). --}}
    <nav x-data x-init="$nextTick(() => { const a = $el.querySelector('[aria-current]'); if (a) $el.scrollLeft = a.offsetLeft - $el.offsetLeft - ($el.clientWidth - a.offsetWidth) / 2 })"
        class="mt-3 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-white/10" aria-label="Telas de Atividades" data-abas-atividades>
        @foreach ($abas as [$rota, $rotulo, $ativas])
            @php $atual = request()->routeIs(...$ativas); @endphp
            <a href="{{ route($rota) }}" wire:navigate @if ($atual) aria-current="page" @endif
                @class(['shrink-0 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', 'border-saude-800 text-saude-800 dark:border-saude-200 dark:text-saude-200' => $atual, 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200' => ! $atual])>{{ $rotulo }}</a>
        @endforeach
    </nav>
</div>
