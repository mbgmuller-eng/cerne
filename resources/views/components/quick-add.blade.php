{{-- Botão redondo "+" fixo no canto inferior direito, por cima da barra de
     navegação. Atalho para lançar receita, despesa ou falar uma despesa.

     No Fluxo de caixa os formulários abrem na hora (evento do Livewire e do
     navegador); nas outras telas o atalho leva ao Fluxo de caixa com
     ?acao=... e a tela abre o formulário (ver CashFlowIndex::mount). Assim
     os formulários continuam sendo um só, com as mesmas validações.

     z-[35]: acima da barra inferior (z-30) e abaixo das janelas (z-40), que
     o cobrem sozinhas. Some com a gaveta do menu aberta (`secao` vem do
     x-data do <body>). transform-gpu pelo mesmo motivo da barra: Android
     Chrome faz elemento fixed sumir na rolagem sem camada própria. --}}
@props(['naTela' => false])

<div
    x-data="{
        aberto: false,
        voz: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
        naTela: @js($naTela),
        ir(acao) {
            this.aberto = false;

            if (! this.naTela) {
                window.location.href = @js(route('cashflow.index')) + '?acao=' + acao;

                return;
            }

            if (acao === 'voz') {
                window.dispatchEvent(new CustomEvent('quick-voice'));

                return;
            }

            Livewire.dispatch('quick-add', { tipo: acao });
        },
    }"
    x-show="secao === null"
    @keydown.escape.window="aberto = false"
    @click.outside="aberto = false"
    class="fixed right-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-[35] transform-gpu will-change-transform lg:right-8 lg:bottom-8"
    data-quick-add
>
    <div
        x-show="aberto"
        x-transition.opacity
        x-cloak
        class="absolute right-0 bottom-16 w-52 overflow-hidden rounded-2xl bg-white py-1.5 text-sm shadow-lg ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-white/10"
        role="menu"
    >
        <button type="button" role="menuitem" @click="ir('receita')" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">
            Adicionar receita
        </button>
        <button type="button" role="menuitem" @click="ir('despesa')" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">
            Adicionar despesa
        </button>
        <button type="button" role="menuitem" x-show="voz" x-cloak @click="ir('voz')" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-white/5">
            Falar despesa
        </button>
    </div>

    <button
        type="button"
        @click="aberto = ! aberto"
        :aria-expanded="aberto"
        aria-haspopup="menu"
        aria-label="Adicionar lançamento"
        class="flex h-14 w-14 items-center justify-center rounded-full bg-accent-600 text-white shadow-lg transition hover:bg-accent-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" class="h-7 w-7 transition-transform duration-200" :class="aberto ? 'rotate-45' : ''" aria-hidden="true">
            <path d="M12 5v14M5 12h14" />
        </svg>
    </button>
</div>
