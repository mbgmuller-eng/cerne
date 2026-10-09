{{-- "Adicionar à agenda": link do Google Agenda + arquivo .ics de uma consulta/exame ($consulta) ou de um
     item de cuidado ($item). As observações cadastradas no Cerne só vão junto se a pessoa marcar a caixa
     (dado de saúde: o padrão é não levar). O dado só sai do Cerne quando ela toca num dos links.
     Os dois endereços (com e sem observações) ficam em data-* e o href troca de um para o outro; sem JS
     vale o endereço sem observações. --}}
@props(['consulta' => null, 'item' => null, 'showPerson' => false])
@use('App\Support\CalendarEvent')

@php
    $modelo = $consulta ?? $item;
    $temObservacao = filled($modelo?->notes);
    $evento = fn (bool $comObservacao) => $consulta
        ? CalendarEvent::forAppointment($consulta, $comObservacao, $showPerson)
        : CalendarEvent::forCareItem($item, $comObservacao, $showPerson);
    $rotaIcs = $consulta ? 'health.appointments.ics' : 'health.care.ics';

    $base = $evento(false);
    $google = $base?->googleUrl();
    $ics = $modelo ? route($rotaIcs, $modelo->id) : null;
    $googleObs = $temObservacao && $base ? $evento(true)->googleUrl() : null;
    $icsObs = $temObservacao && $modelo ? route($rotaIcs, [$modelo->id, 'obs' => 1]) : null;
@endphp

@if ($google)
    <div x-data="{ obs: false }" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
        <span>Adicionar à agenda:</span>
        <a
            href="{{ $google }}"
            @if ($googleObs) data-plain="{{ $google }}" data-notes="{{ $googleObs }}" :href="obs ? $el.dataset.notes : $el.dataset.plain" @endif
            target="_blank"
            rel="noopener noreferrer"
            class="font-medium text-saude-800 hover:underline dark:text-saude-200"
        >Google Agenda</a>
        <a
            href="{{ $ics }}"
            @if ($icsObs) data-plain="{{ $ics }}" data-notes="{{ $icsObs }}" :href="obs ? $el.dataset.notes : $el.dataset.plain" @endif
            class="font-medium text-saude-800 hover:underline dark:text-saude-200"
            title="Funciona no Google, Apple e Outlook"
        >Arquivo .ics</a>
        @if ($googleObs)
            <label class="flex items-center gap-1.5">
                <input type="checkbox" x-model="obs" class="rounded border-slate-300 text-saude-800 focus:ring-saude-500 dark:border-white/20 dark:bg-transparent">
                Incluir minhas observações
            </label>
        @endif
    </div>
@endif
