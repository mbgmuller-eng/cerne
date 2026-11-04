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

/*
 * Fotos de exercício. Cada foto é uma requisição ao PHP (o disco é privado e a rota confere quem pede), e na hospedagem compartilhada o banco
 * recusa conexões demais ao mesmo tempo ("Operation not permitted" ao ler a sessão). Uma tela de treino com 10 exercícios e 2 quadros cada
 * disparava 20 requisições de uma vez. Aqui as fotos entram numa fila de poucas por vez, repetem com pausa se uma falhar e o 2º quadro só é
 * buscado depois que o 1º chegou. O navegador guarda o arquivo (cache longo), então só a primeira visita paga.
 */
const FOTOS_AO_MESMO_TEMPO = 3;
const fotos = { ativas: 0, fila: [] };

function proximaFoto() {
    while (fotos.ativas < FOTOS_AO_MESMO_TEMPO && fotos.fila.length > 0) {
        fotos.ativas++;
        const { url, resolver } = fotos.fila.shift();
        const imagem = new Image();
        const terminar = (ok) => {
            fotos.ativas--;
            resolver(ok);
            proximaFoto();
        };
        imagem.onload = () => terminar(true);
        imagem.onerror = () => terminar(false);
        imagem.src = url;
    }
}

/** Resolve true quando a imagem chegou (e ficou no cache do navegador), false depois de esgotar as tentativas. */
async function carregarFoto(url, tentativas = 3) {
    for (let i = 0; i < tentativas; i++) {
        // `r` muda a URL só nas repetições: o navegador não reaproveita uma resposta de erro.
        const alvo = i === 0 ? url : `${url}${url.includes('?') ? '&' : '?'}r=${i}`;
        const ok = await new Promise((resolver) => {
            fotos.fila.push({ url: alvo, resolver });
            proximaFoto();
        });

        if (ok) {
            return alvo;
        }

        await new Promise((r) => setTimeout(r, 1500 * (i + 1) + Math.random() * 1000));
    }

    return null;
}

document.addEventListener('alpine:init', () => {
    Alpine.data('gymPhoto', (frames, animated) => ({
        frames,
        i: 0,
        src: '',
        open: false,
        timer: null,

        async init() {
            const primeiro = await carregarFoto(frames[0]);

            if (primeiro === null) {
                return;
            }

            this.frames[0] = primeiro;
            this.src = primeiro;

            if (! animated || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }

            // Só alterna quando o 2º quadro já chegou: trocar para uma URL ainda não carregada piscava a foto quebrada.
            const segundo = await carregarFoto(frames[1]);

            if (segundo === null) {
                return;
            }

            this.frames[1] = segundo;
            this.timer = setInterval(() => {
                this.i = 1 - this.i;
                this.src = this.frames[this.i];
            }, 1100);
        },

        destroy() {
            clearInterval(this.timer);
        },
    }));
});
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
     * Documentos: "Ver" uma IMAGEM antes de baixar — a pessoa às vezes não
     * tem certeza de qual documento é, e precisa olhar sem sair da tela.
     *
     * Só imagem: PDF não passa por aqui — nenhum navegador (Android,
     * Desktop, iPhone) sabe renderizar PDF dentro de um elemento embutido,
     * só em navegação de página inteira, então "Ver" num PDF abre a URL
     * numa aba nova direto (ver o botão na blade), sem passar por este
     * store. Imagem sempre funcionou bem num <img> normal.
     *
     * Store global (não Alpine.data por linha) porque o visualizador é UM
     * modal só, compartilhado por toda a lista — cada linha só manda abrir
     * com a URL dela. Busca o arquivo como blob: nunca navega a página,
     * então o mesmo problema do botão de baixar (iPhone preso na
     * visualização do Safari, sem "voltar") nem chega a existir aqui —
     * fechar o modal é só escrever `aberto = false`, sem histórico de
     * navegação nenhum de verdade envolvido.
     */
    Alpine.store('documentViewer', {
        aberto: false,
        carregando: false,
        erro: '',
        titulo: '',
        nomeArquivo: '',
        blobUrl: null,

        async abrir(url, titulo, nomeArquivo) {
            this.erro = '';
            this.titulo = titulo;
            this.nomeArquivo = nomeArquivo;
            this.blobUrl = null;
            this.carregando = true;
            this.aberto = true;

            try {
                const resposta = await fetch(url, { credentials: 'same-origin' });

                if (!resposta.ok) {
                    throw new Error('visualizacao-falhou');
                }

                this.blobUrl = URL.createObjectURL(await resposta.blob());
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

            this.blobUrl = null;
        },
    });
});
