/**
 * Tema (claro/escuro/sistema): aplica a classe em <html> na hora e salva
 * a preferência no servidor em segundo plano — ver
 * App\Http\Controllers\ThemePreferenceController.
 *
 * Quando a preferência é explícita (claro/escuro), o <html> já nasce com
 * a classe certa renderizada pelo servidor — sem flash. Este script cobre
 * dois casos que o servidor não sabe decidir sozinho: "sistema" (olha o
 * SO via matchMedia) e a troca ao vivo quando a pessoa clica num botão.
 */
(function () {
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    function applyIfSystem() {
        if (root.dataset.themePreference === 'system') {
            root.classList.toggle('dark', media.matches);
        }
    }

    media.addEventListener('change', applyIfSystem);

    function setActiveButton(value) {
        document.querySelectorAll('[data-theme-switcher] [data-theme-value]').forEach((button) => {
            button.classList.toggle('theme-switch-active', button.dataset.themeValue === value);
        });
    }

    function applyTheme(value) {
        root.dataset.themePreference = value;
        root.classList.toggle('dark', value === 'dark' || (value === 'system' && media.matches));
        setActiveButton(value);
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-theme-switcher] [data-theme-value]');

        if (!button) {
            return;
        }

        const value = button.dataset.themeValue;
        applyTheme(value);

        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        fetch('/preferencias/tema', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
                Accept: 'application/json',
            },
            body: JSON.stringify({ theme: value }),
            keepalive: true,
        }).catch(() => {
            // Preferência de tela, não dado financeiro: falhar em silêncio
            // é melhor que travar a troca visual por causa da rede.
        });
    });
})();

/**
 * "Falar despesa" (Fluxo de caixa) — transcreve com a Web Speech API
 * nativa do navegador (sem custo de servidor, sem lib externa) e manda
 * o texto pro Livewire, que só usa pra PRÉ-PREENCHER o formulário de
 * despesa de sempre — a pessoa ainda revisa e confirma antes de salvar
 * (ver CashFlowIndex::processVoiceExpense).
 *
 * Registrado em app.js (carregado uma vez, fora da árvore que o
 * Livewire remonta a cada atualização) em vez de inline no componente
 * — um <script> dentro do root do Livewire arriscaria redeclarar a
 * função (ou perder o estado do reconhecimento em andamento) toda vez
 * que a tela reage a um wire:click.
 *
 * Suporte real: Chrome/Android reconhece bem; Safari/iOS é instável —
 * por isso o botão só aparece quando `window.SpeechRecognition` (ou o
 * prefixo `webkit`) existe de fato.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('voiceExpense', () => ({
        suportado: false,
        gravando: false,
        processando: false,
        erro: '',
        reconhecimento: null,

        init() {
            const Reconhecimento = window.SpeechRecognition || window.webkitSpeechRecognition;
            this.suportado = !!Reconhecimento;

            if (!this.suportado) {
                return;
            }

            this.reconhecimento = new Reconhecimento();
            this.reconhecimento.lang = 'pt-BR';
            this.reconhecimento.interimResults = false;
            this.reconhecimento.maxAlternatives = 1;

            this.reconhecimento.addEventListener('result', (evento) => {
                const texto = evento.results[0]?.[0]?.transcript ?? '';
                this.processarTranscricao(texto);
            });

            this.reconhecimento.addEventListener('end', () => {
                this.gravando = false;
            });

            this.reconhecimento.addEventListener('error', (evento) => {
                this.gravando = false;

                if (evento.error === 'no-speech') {
                    return; // silêncio até o timeout — não é bem um erro, só não falou nada.
                }

                this.erro = evento.error === 'not-allowed'
                    ? 'Permita o microfone do navegador pra usar essa função.'
                    : 'Não consegui ouvir. Tente de novo.';
            });
        },

        alternar() {
            this.erro = '';

            if (this.gravando) {
                this.reconhecimento.stop();

                return;
            }

            this.gravando = true;
            this.reconhecimento.start();
        },

        rotulo() {
            if (this.processando) return 'Entendendo...';
            if (this.gravando) return 'Ouvindo — toque pra parar';

            return 'Falar despesa';
        },

        async processarTranscricao(texto) {
            if (texto.trim() === '') {
                this.erro = 'Não entendi nada. Tente de novo.';

                return;
            }

            this.processando = true;

            try {
                const entendeu = await this.$wire.call('processVoiceExpense', texto);

                if (!entendeu) {
                    this.erro = 'Não consegui entender o áudio. Tente de novo ou preencha à mão.';
                }
            } catch (e) {
                this.erro = 'Não consegui processar. Tente de novo ou digite.';
            } finally {
                this.processando = false;
            }
        },
    }));
});
