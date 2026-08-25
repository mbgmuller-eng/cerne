@props(['class' => 'h-7 w-7'])

{{-- Marca do Cerne: anéis de crescimento + núcleo emerald.
     SVG estático em public/branding/ (ver resources/branding/ para a
     fonte e scripts/generate-icons.mjs para os ícones do PWA gerados
     a partir dele). --}}
<img
    src="{{ asset('branding/cerne-icon.svg') }}"
    alt=""
    {{ $attributes->merge(['class' => $class.' shrink-0 rounded-lg']) }}
>
