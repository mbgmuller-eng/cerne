/*
 * Service worker do Cerne.
 *
 * A decisão que governa este arquivo: NADA de dado financeiro entra no
 * cache. Um saldo ou uma fatura servidos do cache seriam um número errado
 * apresentado como certo — e o usuário não tem como saber que está velho.
 * Pior ainda num aparelho compartilhado, onde a resposta em cache de uma
 * sessão poderia aparecer depois do logout.
 *
 * Por isso o cache cobre apenas os arquivos estáticos com hash no nome
 * (build do Vite, ícones). Tudo que vem do servidor vai à rede sempre.
 */

const CACHE = 'cerne-estatico-v1';

// Só o que é imutável: o Vite põe hash no nome, então uma versão nova
// gera outra URL e o cache antigo é descartado pela limpeza abaixo.
const PADROES_CACHEAVEIS = [
    /\/build\/assets\//,
    /\/icons\//,
];

self.addEventListener('install', () => {
    // Assume o controle sem esperar as abas antigas fecharem.
    self.skipWaiting();
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches.keys()
            .then((chaves) => Promise.all(
                chaves.filter((c) => c !== CACHE).map((c) => caches.delete(c))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (evento) => {
    const requisicao = evento.request;

    // Só GET: POST muda estado e nunca pode ser servido do cache.
    if (requisicao.method !== 'GET') {
        return;
    }

    const url = new URL(requisicao.url);

    // Requisição para outro domínio não é nossa para gerenciar.
    if (url.origin !== self.location.origin) {
        return;
    }

    const cacheavel = PADROES_CACHEAVEIS.some((padrao) => padrao.test(url.pathname));

    if (!cacheavel) {
        // Deixa passar direto para a rede. Sem interceptar, sem cache.
        return;
    }

    evento.respondWith(
        caches.match(requisicao).then((emCache) => {
            if (emCache) {
                return emCache;
            }

            return fetch(requisicao).then((resposta) => {
                // Só guarda resposta completa e bem-sucedida.
                if (resposta.ok && resposta.status === 200) {
                    const copia = resposta.clone();
                    caches.open(CACHE).then((cache) => cache.put(requisicao, copia));
                }

                return resposta;
            });
        })
    );
});
