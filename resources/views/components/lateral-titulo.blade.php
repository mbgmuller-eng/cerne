@props(['chave', 'rotulo'])

{{--
    Título de uma área do menu lateral do computador: um botão que abre e fecha os itens dela (resources/js/app.js; a escolha fica no navegador).
    Com a barra recolhida em trilha de ícones, vira só a linha que separa os grupos, como antes.
--}}
<button type="button" data-alternar-secao="{{ $chave }}" aria-expanded="true" title="Abrir ou fechar {{ $rotulo }}"
    {{ $attributes->class('lateral-titulo flex w-full items-center justify-between gap-2 rounded-lg px-3 py-1 text-[10px] font-semibold tracking-wide uppercase transition hover:bg-black/5 dark:hover:bg-white/5') }}>
    <span class="min-w-0 truncate text-left">{{ $rotulo }}{{ $slot }}</span>
    <svg class="lateral-seta lateral-texto h-3.5 w-3.5 shrink-0 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
</button>
