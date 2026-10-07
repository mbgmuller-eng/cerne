@props(['consulta'])

@php
    // Título montado sozinho ("Especialidade · Profissional") não precisa aparecer duas vezes.
    $composto = implode(' · ', array_filter([$consulta->specialty, $consulta->professional_name]));
    $quemAtende = $composto !== '' && $composto !== $consulta->title ? $composto : null;
    $onde = implode(' · ', array_filter([$consulta->location, $consulta->address]));
    $agendamento = implode(' · ', array_filter([
        $consulta->booked_by_name ? 'por '.$consulta->booked_by_name : null,
        $consulta->booked_with_name ? 'com '.$consulta->booked_with_name : null,
    ]));
@endphp

@if ($quemAtende || $onde !== '' || $consulta->phone || $agendamento !== '' || $consulta->notes)
    <div class="mt-1 space-y-0.5 text-xs text-slate-500 dark:text-slate-400">
        @if ($quemAtende)
            <p>{{ $quemAtende }}</p>
        @endif
        @if ($onde !== '')
            <p>{{ $onde }}</p>
        @endif
        @if ($consulta->phone)
            <p>Telefone: <a href="tel:{{ preg_replace('/[^\d+]/', '', $consulta->phone) }}" class="underline hover:text-slate-700 dark:hover:text-slate-200">{{ $consulta->phone }}</a></p>
        @endif
        @if ($agendamento !== '')
            <p>Agendado {{ $agendamento }}</p>
        @endif
        @if ($consulta->notes)
            <p class="whitespace-pre-line">{{ $consulta->notes }}</p>
        @endif
    </div>
@endif
