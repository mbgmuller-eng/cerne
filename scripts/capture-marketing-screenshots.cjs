// Captura screenshots em alta resolução (2x) do app local para as páginas públicas (landing e /profissionais).
// Usa o Chrome já instalado (puppeteer-core, sem baixar Chromium). Precisa do servidor local em 127.0.0.1:8000
// e do banco com os dados de demonstração (DevSeeder + DemoDataSeeder + os de histórico).
//
//   node scripts/capture-marketing-screenshots.cjs                      # tudo, direto em public/images/marketing
//   node scripts/capture-marketing-screenshots.cjs --only=painel,contas  # só algumas
//   node scripts/capture-marketing-screenshots.cjs --out=C:/pasta        # em outra pasta (para revisar antes de trocar)
const puppeteer = require('puppeteer-core');
const path = require('path');
const fs = require('fs');

const CHROME_PATH = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const arg = (nome) => (process.argv.find((a) => a.startsWith(`--${nome}=`)) || '').replace(`--${nome}=`, '');
const OUT = arg('out') || path.join(__dirname, '..', 'public', 'images', 'marketing');
const only = arg('only') ? arg('only').split(',') : null;
const wanted = (name) => only === null || only.includes(name);
fs.mkdirSync(OUT, { recursive: true });

const wait = (ms) => new Promise((r) => setTimeout(r, ms));

async function login(page, email, password) {
    await page.goto(`${BASE}/entrar`, { waitUntil: 'networkidle0' });
    await page.type('#email', email);
    await page.type('#password', password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type="submit"]')]);
}

async function setLightTheme(page) {
    await page.evaluate(() => {
        document.documentElement.classList.remove('dark');
        document.documentElement.dataset.themePreference = 'light';
    });
    const csrf = await page.$eval('meta[name="csrf-token"]', (el) => el.content);
    await page.evaluate(async (token) => {
        await fetch('/preferencias/tema', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ theme: 'light' }) });
    }, csrf);
}

// Sem cartão de "primeira vez nesta tela" (é do tutorial, não do produto) e sem o botão "+" por cima do conteúdo.
async function limpar(page) {
    await page.evaluate(() => {
        document.querySelectorAll('[data-quick-add], [data-voltar-topo]').forEach((e) => (e.style.display = 'none'));
        [...document.querySelectorAll('div')].filter((e) => /Primeira vez nesta tela/.test(e.textContent) && e.textContent.length < 400).forEach((e) => (e.style.display = 'none'));
    });
}

async function snap(page, file, clip) {
    await wait(500);
    await limpar(page);
    await page.screenshot({ path: path.join(OUT, `${file}.jpg`), type: 'jpeg', quality: 92, ...(clip ? { clip } : {}) });
    console.log('ok:', file);
}

async function shoot(page, url, name, prepare, clip) {
    if (!wanted(name)) return;
    await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle0' });
    if (prepare) await prepare(page);
    await snap(page, name, clip);
}

// Rola até um título pelo texto.
const scrollToText = (texto) => (page) => page.evaluate((t) => {
    const el = [...document.querySelectorAll('h1, h2, h3, p')].find((e) => e.textContent.trim().startsWith(t));
    if (el) { el.scrollIntoView(); window.scrollBy(0, -24); }
}, texto);

(async () => {
    const browser = await puppeteer.launch({ executablePath: CHROME_PATH, headless: 'new', defaultViewport: { width: 1440, height: 900, deviceScaleFactor: 2 } });

    // ---- Cliente final: Ana Ribeiro (Família Ribeiro, dado de demonstração)
    const client = await browser.newPage();
    await login(client, 'ana@cerne.test', 'password');
    await setLightTheme(client);
    await shoot(client, '/painel', 'painel');
    await shoot(client, '/fluxo-de-caixa', 'fluxo-de-caixa');
    await shoot(client, '/contas', 'contas');
    await shoot(client, '/objetivos', 'objetivos');
    await shoot(client, '/regra', 'regra');
    await shoot(client, '/dividas', 'dividas');
    await shoot(client, '/importar', 'importar');
    await shoot(client, '/investimentos', 'investimentos', scrollToText('Perfil do investidor'));
    await shoot(client, '/seguros', 'seguros');
    // Abre a pasta "Pessoais" (a tela abre numa pasta vazia).
    await shoot(client, '/documentos', 'documentos', async (page) => {
        await page.evaluate(() => {
            const el = [...document.querySelectorAll('button, a')].find((e) => e.textContent.trim().startsWith('Pessoais'));
            if (el) el.click();
        });
        await wait(1500);
    });
    await shoot(client, '/saude/atividades', 'saude');
    await shoot(client, '/saude/agenda', 'saude-agenda');
    await shoot(client, '/saude/ficha', 'saude-ficha');
    await shoot(client, '/acompanhamento', 'acompanhamento');
    await shoot(client, '/busca?q=reserva', 'busca');

    // Extrato: abre o da primeira conta da lista.
    if (wanted('extrato')) {
        await client.goto(`${BASE}/contas`, { waitUntil: 'networkidle0' });
        // A conta corrente (Nubank) é a que tem movimentação no mês; a poupança fica parada.
        const href = await client.$$eval('a[href*="/extrato"]', (as) => { const lista = Array.from(as); const alvo = lista.find((a) => /Nubank/.test((a.closest('li, div') || {}).textContent || '')) || lista[0]; return alvo ? alvo.href : null; });
        if (!href) throw new Error('link do extrato não encontrado');
        await client.goto(href, { waitUntil: 'networkidle0' });
        await snap(client, 'extrato');
    }

    // Celular: o painel e o menu aberto.
    if (wanted('celular-painel') || wanted('celular-menu')) {
        const m = await browser.newPage();
        await m.setViewport({ width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
        await m.goto(`${BASE}/painel`, { waitUntil: 'networkidle0' });
        await snap(m, 'celular-painel');
        if (wanted('celular-menu')) {
            await m.evaluate(() => document.querySelector('[data-abrir-menu]')?.click());
            await wait(700);
            await snap(m, 'celular-menu');
        }
        await m.close();
    }
    await client.close();

    // ---- Profissional: consultor de demonstração. Contexto separado (a página do cliente deixou cookie de sessão no padrão).
    const proContext = await browser.createBrowserContext();
    const pro = await proContext.newPage();
    await pro.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
    await login(pro, 'consultor@cerne.test', 'password');
    await setLightTheme(pro);
    await shoot(pro, '/carteira', 'carteira-consultor');
    await shoot(pro, '/carteira/seguros', 'carteira-seguros');
    await shoot(pro, '/carteira/investimentos', 'carteira-investimentos');
    await shoot(pro, '/carteira/dividas', 'carteira-dividas');
    await shoot(pro, '/carteira/regra', 'carteira-regra');
    await shoot(pro, '/carteira/datas-importantes', 'carteira-datas', async (page) => {
        const valores = await page.$$eval('select option', (opts) => opts.map((o) => o.value));
        const maior = valores[valores.length - 1];
        if (maior) await page.select('select', maior);
        await wait(1500);
        await page.evaluate(() => {
            const aba = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Vencimento de apólice');
            if (aba) aba.click();
        });
        await wait(1500);
    }, { x: 0, y: 0, width: 1440, height: 400 });
    await shoot(pro, '/semana', 'semana');
    await shoot(pro, '/agenda', 'agenda');
    await shoot(pro, '/recebimentos', 'recebimentos');
    await shoot(pro, '/leads', 'leads');
    await shoot(pro, '/leads/metricas', 'leads-metricas');
    await shoot(pro, '/modelos-de-roteiro', 'modelos-de-roteiro');

    // Cliente aberto pelo profissional: o menu não tem Saúde e a faixa avisa que ele está vendo o perfil "como consultor".
    if (wanted('carteira-cliente')) {
        await pro.goto(`${BASE}/carteira/regra`, { waitUntil: 'networkidle0' });
        // Linha "Ana e Bruno Ribeiro": o botão "Abrir perfil" dela.
        await Promise.all([
            pro.waitForNavigation({ waitUntil: 'networkidle0' }),
            pro.evaluate(() => {
                const linha = [...document.querySelectorAll('div, tr, li')].filter((e) => e.textContent.includes('Ana e Bruno Ribeiro') && e.querySelector('button, a'))
                    .sort((a, b) => a.textContent.length - b.textContent.length)[0];
                const botao = [...linha.querySelectorAll('button, a')].find((b) => b.textContent.trim() === 'Abrir perfil');
                botao.click();
            }),
        ]);
        await pro.goto(`${BASE}/painel`, { waitUntil: 'networkidle0' });
        await snap(pro, 'carteira-cliente');
    }
    await proContext.close();

    await browser.close();
    console.log('done');
})().catch((err) => { console.error(err); process.exit(1); });
