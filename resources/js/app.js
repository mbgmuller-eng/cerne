// import() dinâmico, não estático: pdf.js pesa ~400KB, e só a tela de
// Documentos usa isto — carregado de verdade só na primeira vez que
// alguém clica "Ver" num PDF, não em toda página do app (app.js é
// carregado em TODAS elas).
let pdfjsLibPromise = null;

function carregarPdfjs() {
    pdfjsLibPromise ??= import('pdfjs-dist').then((pdfjsLib) => {
        // Vite resolve isto pro arquivo real do worker e empacota como
        // asset próprio — sem isso, pdf.js tenta buscar o worker de um
        // caminho que não existe no build e falha calado.
        pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).href;

        return pdfjsLib;
    });

    return pdfjsLibPromise;
}

/**
 * Desenha cada página do PDF num <canvas>, empilhados dentro de `container`.
 *
 * Por que pdf.js e não um <iframe>/<embed> apontando pro blob: SÓ o Chrome
 * de desktop tem visualizador de PDF nativo embutível num iframe — Android
 * (Chrome, e os outros também) não tem NENHUM visualizador embutível, e
 * mostra um retângulo cinza com um botão "Abrir" que nem funciona (blob:
 * não navega pra fora da página que criou). pdf.js desenha em canvas —
 * funciona igual em qualquer navegador, porque não depende de plugin
 * nenhum do sistema.
 */
async function renderizarPdf(container, blob) {
    container.innerHTML = '';

    const pdfjsLib = await carregarPdfjs();
    const pdf = await pdfjsLib.getDocument({ data: await blob.arrayBuffer() }).promise;

    for (let numeroPagina = 1; numeroPagina <= pdf.numPages; numeroPagina++) {
        const pagina = await pdf.getPage(numeroPagina);
        const viewport = pagina.getViewport({ scale: 1.5 });

        const canvas = document.createElement('canvas');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        canvas.className = 'mx-auto mb-2 max-w-full rounded-lg border border-slate-100 dark:border-white/10';
        canvas.style.width = '100%';
        canvas.style.height = 'auto';
        container.appendChild(canvas);

        await pagina.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    }
}

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

    // wire:navigate busca a página seguinte e faz o morph do <html> inteiro
    // contra o HTML que o servidor devolveu — que, em "sistema", nunca tem
    // a classe "dark" (só o JS decide isso). O morph troca a classe pela
    // versão sem "dark" e não reexecuta o script inline do <head> (script
    // idêntico entre páginas, o morphdom não considera isso uma mudança).
    // Resultado: cada navegação interna voltava pro claro mesmo com o
    // sistema em escuro. Reaplica a mesma lógica depois de cada morph.
    document.addEventListener('livewire:navigated', applyIfSystem);

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
/**
 * Baixa um arquivo forçando o "Salvar como" do navegador em vez de
 * navegar/abrir embutido — usado tanto pelo QR de emergência quanto pela
 * área de Documentos. Buscar como blob (em vez de só um <a href download>
 * apontando pra URL) é o que garante o download de verdade também no
 * Safari/iOS: o Safari tem visualizador nativo de PDF/imagem que ignora o
 * atributo `download` e abre o arquivo por cima do app — inclusive dentro
 * de um PWA instalado na tela de início, onde não existe "voltar" nenhum
 * pra essa visualização, forçando a pessoa a fechar e reabrir o app.
 * Baixar como blob nunca navega a página, então esse problema não existe.
 */
async function baixarArquivo(url, nomeArquivo) {
    const resposta = await fetch(url, { credentials: 'same-origin' });

    if (!resposta.ok) {
        throw new Error('download-falhou');
    }

    const blob = await resposta.blob();
    const blobUrl = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = blobUrl;
    link.download = nomeArquivo;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(blobUrl);
}

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

    /**
     * QR Code de emergência: DUAS ações bem separadas, porque resolvem
     * problemas diferentes.
     *
     * `compartilharLink` manda o LINK (não a imagem) pela folha de
     * compartilhamento nativa — é o que faz sentido pra mandar pra uma
     * pessoa (ela toca e abre a ficha, sem precisar escanear nada).
     * Compartilhar a imagem do QR por WhatsApp não serve pra isso: quem
     * recebe só vê uma figura, sem como "clicar" nela.
     *
     * `baixarImagem` continua baixando o PNG de verdade — serve pra
     * imprimir ou guardar como foto (carteira, geladeira), onde faz
     * sentido ter o código pra ESCANEAR, não um link pra clicar.
     */
    Alpine.data('qrShare', (config) => ({
        url: config.url,
        imageUrl: config.imageUrl,
        title: config.title,
        fileName: config.fileName,
        compartilhando: false,
        baixando: false,
        mensagem: '',
        erro: '',

        async compartilharLink() {
            this.erro = '';
            this.mensagem = '';
            this.compartilhando = true;

            try {
                if (navigator.share) {
                    await navigator.share({ title: this.title, url: this.url });

                    return;
                }

                await navigator.clipboard.writeText(this.url);
                this.mensagem = 'Link copiado!';
            } catch (e) {
                // AbortError: a pessoa cancelou a folha de compartilhamento — não é erro.
                if (e?.name !== 'AbortError') {
                    this.erro = 'Não foi possível compartilhar o link agora.';
                }
            } finally {
                this.compartilhando = false;
            }
        },

        async baixarImagem() {
            this.erro = '';
            this.mensagem = '';
            this.baixando = true;

            try {
                await baixarArquivo(this.imageUrl, this.fileName);
            } catch (e) {
                this.erro = 'Não foi possível baixar a imagem agora.';
            } finally {
                this.baixando = false;
            }
        },
    }));

    /** Documentos: baixar o arquivo original — ver baixarArquivo() acima pro motivo de não ser um <a download> simples. */
    Alpine.data('documentDownload', () => ({
        baixando: false,
        erro: '',

        async baixar(url, nomeArquivo) {
            this.erro = '';
            this.baixando = true;

            try {
                await baixarArquivo(url, nomeArquivo);
            } catch (e) {
                this.erro = 'Não foi possível baixar o documento agora.';
            } finally {
                this.baixando = false;
            }
        },
    }));

    /**
     * Documentos: "Ver" antes de baixar — a pessoa às vezes não tem
     * certeza de qual documento é, e precisa olhar sem sair da tela.
     *
     * Store global (não Alpine.data por linha) porque o visualizador é UM
     * modal só, compartilhado por toda a lista — cada linha só manda abrir
     * com a URL/tipo dela. Busca o arquivo como blob: nunca navega a
     * página, então o mesmo problema do botão de baixar (iPhone preso na
     * visualização do Safari, sem "voltar") nem chega a existir aqui —
     * fechar o modal é só escrever `aberto = false`, sem histórico de
     * navegação nenhum de verdade envolvido.
     *
     * `pdfContainer` é o elemento onde o PDF é desenhado — registrado uma
     * vez pelo x-init do próprio modal (ver a blade), porque $refs é por
     * componente Alpine e o botão de cada linha é um componente diferente
     * do modal.
     */
    Alpine.store('documentViewer', {
        aberto: false,
        carregando: false,
        erro: '',
        titulo: '',
        mimeType: '',
        nomeArquivo: '',
        blob: null,
        blobUrl: null,
        pdfContainer: null,

        async abrir(url, mimeType, titulo, nomeArquivo) {
            this.erro = '';
            this.titulo = titulo;
            this.mimeType = mimeType;
            this.nomeArquivo = nomeArquivo;
            this.blob = null;
            this.blobUrl = null;
            this.carregando = true;
            this.aberto = true;

            try {
                const resposta = await fetch(url, { credentials: 'same-origin' });

                if (!resposta.ok) {
                    throw new Error('visualizacao-falhou');
                }

                this.blob = await resposta.blob();
                // blobUrl serve pro <img> (imagem) e pro botão "Baixar" dos
                // dois tipos — o PDF, além disso, é desenhado à parte.
                this.blobUrl = URL.createObjectURL(this.blob);

                if (this.mimeType === 'application/pdf') {
                    await renderizarPdf(this.pdfContainer, this.blob);
                }
            } catch (e) {
                this.erro = 'Não foi possível abrir o documento agora.';
            } finally {
                this.carregando = false;
            }
        },

        baixar() {
            if (this.blobUrl === null) {
                return;
            }

            const link = document.createElement('a');
            link.href = this.blobUrl;
            link.download = this.nomeArquivo;
            document.body.appendChild(link);
            link.click();
            link.remove();
        },

        fechar() {
            this.aberto = false;

            if (this.blobUrl !== null) {
                URL.revokeObjectURL(this.blobUrl);
            }

            this.blob = null;
            this.blobUrl = null;

            if (this.pdfContainer !== null) {
                this.pdfContainer.innerHTML = '';
            }
        },
    });
});
