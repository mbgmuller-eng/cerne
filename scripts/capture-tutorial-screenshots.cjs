// Capturas do vídeo "Primeiros passos no financeiro" (projeto Cerne-Videos). Para cada passo tira duas imagens, no computador e no celular:
//   <nome>-a  a tela com o botão que a pessoa toca (a posição do botão vai em caixas.json, para o vídeo desenhar o destaque)
//   <nome>-b  o formulário aberto e preenchido com dados fictícios
// Precisa do servidor local em 127.0.0.1:8000 e dos dados de demonstração (ana@cerne.test).
//
//   node scripts/capture-tutorial-screenshots.cjs                 # em C:/Users/mbgmu/Cerne-Videos/public/telas6
//   node scripts/capture-tutorial-screenshots.cjs --out=C:/pasta
const puppeteer = require('puppeteer-core');
const path = require('path');
const fs = require('fs');

const CHROME_PATH = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const arg = (nome) => (process.argv.find((a) => a.startsWith(`--${nome}=`)) || '').replace(`--${nome}=`, '');
const OUT = arg('out') || 'C:/Users/mbgmu/Cerne-Videos/public/telas6';
const wait = (ms) => new Promise((r) => setTimeout(r, ms));

const VISOES = {
    desktop: { width: 1440, height: 900, deviceScaleFactor: 2 },
    mobile: { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true },
};

const caixas = {};

async function login(page, email, password) {
    await page.goto(`${BASE}/entrar`, { waitUntil: 'networkidle0' });
    await page.type('#email', email);
    await page.type('#password', password);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('button[type="submit"]')]);
}

async function tema(page) {
    await page.evaluate(() => { document.documentElement.classList.remove('dark'); document.documentElement.dataset.themePreference = 'light'; });
    const csrf = await page.$eval('meta[name="csrf-token"]', (el) => el.content);
    await page.evaluate(async (token) => {
        await fetch('/preferencias/tema', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ theme: 'light' }) });
    }, csrf);
}

async function limpar(page) {
    await page.evaluate(() => {
        document.querySelectorAll('[data-quick-add], [data-voltar-topo]').forEach((e) => (e.style.display = 'none'));
        [...document.querySelectorAll('div')].filter((e) => /Primeira vez nesta tela/.test(e.textContent) && e.textContent.length < 400).forEach((e) => (e.style.display = 'none'));
    });
}

async function snap(page, visao, nome) {
    await wait(600);
    await limpar(page);
    fs.mkdirSync(path.join(OUT, visao), { recursive: true });
    await page.screenshot({ path: path.join(OUT, visao, `${nome}.jpg`), type: 'jpeg', quality: 92 });
    console.log('ok:', visao, nome);
}

// O formulário INTEIRO (a janela do cadastro, com o botão de salvar), mesmo o que a tela esconde atrás da rolagem da janela: o vídeo anima a janela
// abrindo e rolando de verdade, de cima até o fim. Aumenta a altura da tela só para a captura, para a janela não precisar rolar por dentro.
async function snapCard(page, visao, nome, vp) {
    // Espera as requisições do Livewire acabarem (os campos atualizam o quadro "Ao salvar"): com uma no ar, o botão de salvar aparece apagado.
    await page.waitForNetworkIdle({ idleTime: 800, timeout: 8000 }).catch(() => {});
    await page.setViewport({ ...vp, height: 3200 });
    await page.waitForNetworkIdle({ idleTime: 800, timeout: 8000 }).catch(() => {});
    await wait(600);
    await limpar(page);
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    const painel = await page.evaluateHandle(() => [...document.querySelectorAll('.fixed.inset-0 .card')].find((e) => e.getClientRects().length > 0) || null);
    const el = painel.asElement();
    if (!el) throw new Error('janela do formulário não encontrada');
    const caixa = await el.boundingBox();
    fs.mkdirSync(path.join(OUT, visao), { recursive: true });
    await el.screenshot({ path: path.join(OUT, visao, `${nome}-card.jpg`), type: 'jpeg', quality: 92 });
    await page.setViewport(vp);
    console.log('ok:', visao, `${nome}-card`, Math.round(caixa.width), 'x', Math.round(caixa.height));
    return { w: Math.round(caixa.width * 100) / 100, h: Math.round(caixa.height * 100) / 100 };
}

// Caixa (em px do CSS) de um botão pelo texto, para o destaque do vídeo.
async function caixaDoBotao(page, texto) {
    return page.evaluate((t) => {
        const e = [...document.querySelectorAll('button, a')].filter((x) => x.offsetParent !== null).find((x) => x.textContent.trim() === t);
        if (!e) return null;
        e.scrollIntoView({ block: 'center' });
        const r = e.getBoundingClientRect();
        return { x: r.x, y: r.y, w: r.width, h: r.height };
    }, texto);
}

async function clicar(page, texto) {
    const ok = await page.evaluate((t) => {
        const e = [...document.querySelectorAll('button, a')].filter((x) => x.offsetParent !== null).find((x) => x.textContent.trim() === t);
        if (e) e.click();
        return !!e;
    }, texto);
    if (!ok) throw new Error(`botão "${texto}" não encontrado`);
    await wait(1000);
}

// Preenche pelo texto do rótulo: digita nos campos e escolhe a opção que contém o texto nos selects.
async function preencher(page, rotulo, valor) {
    const h = await page.evaluateHandle((r) => {
        const l = [...document.querySelectorAll('label')].filter((x) => x.offsetParent !== null).find((x) => x.textContent.trim().startsWith(r));
        return l ? l.parentElement.querySelector('input:not([type=checkbox]):not([type=hidden]), select') : null;
    }, rotulo);
    const el = h.asElement();
    if (!el) throw new Error(`campo "${rotulo}" não encontrado`);
    const tag = await el.evaluate((e) => e.tagName);
    if (tag === 'SELECT') {
        const v = await el.evaluate((e, t) => (([...e.options].find((o) => o.textContent.toLowerCase().includes(t.toLowerCase())) || {}).value), valor);
        if (v !== undefined) await el.select(v);
    } else {
        await el.click();
        await page.keyboard.down('Control');
        await page.keyboard.press('KeyA');
        await page.keyboard.up('Control');
        await page.keyboard.press('Backspace');
        await el.type(valor);
    }
    await wait(150);
}

// Escolhe a opção (pelo texto) do select ligado a um wire:model, em qualquer variação (.live, .blur...).
async function selecionar(page, modelo, texto) {
    const h = await page.evaluateHandle((m) => [...document.querySelectorAll('select')].find((e) => [...e.attributes].some((a) => a.name.startsWith('wire:model') && a.value === m)) || null, modelo);
    const el = h.asElement();
    if (!el) throw new Error(`select ${modelo} não encontrado`);
    const v = await el.evaluate((e, t) => (([...e.options].find((o) => o.textContent.toLowerCase().includes(t.toLowerCase())) || {}).value), texto);
    if (v === undefined) throw new Error(`opção "${texto}" não encontrada em ${modelo}`);
    await el.select(v);
    await page.waitForNetworkIdle({ idleTime: 600, timeout: 8000 }).catch(() => {});
}

// A revisão do extrato com a regra ligada a uma conta fixa: o primeiro item, a regra marcada, a conta escolhida e o quadro "Ao importar" (a janela inteira, tela alta).
async function capturarRevisao(page, visao, vpTela) {
    // No computador a revisão sai numa tela mais estreita (1100): no vídeo ela é encolhida para caber, e o texto fica maior.
    const vp = visao === 'desktop' ? { ...vpTela, width: 1100, height: 900 } : vpTela;
    await page.setViewport(vp);
    await page.goto(`${BASE}/importar`, { waitUntil: 'networkidle0' });
    await limpar(page);
    await page.evaluate(() => {
        const li = [...document.querySelectorAll('li')].find((e) => e.textContent.includes('extrato-outubro.pdf'));
        [...(li ? li.querySelectorAll('button') : [])].find((b) => b.textContent.trim() === 'Revisar')?.click();
    });
    await page.waitForNetworkIdle({ idleTime: 800, timeout: 10000 }).catch(() => {});
    await wait(800);
    await selecionar(page, 'necessidadePorItem.0', 'Essencial');
    await selecionar(page, 'categoriaPorItem.0', 'Habita');
    const subs = await page.evaluate(() => {
        const e = [...document.querySelectorAll('select')].find((x) => [...x.attributes].some((a) => a.name.startsWith('wire:model') && a.value === 'subcategoriaPorItem.0'));
        return e ? [...e.options].map((o) => o.textContent.trim()).filter((t) => t && !/selecione/i.test(t)) : [];
    });
    if (subs.length) await selecionar(page, 'subcategoriaPorItem.0', subs.find((t) => /aluguel/i.test(t)) || subs[0]);
    await page.evaluate(() => {
        const c = [...document.querySelectorAll('input[type=checkbox]')].find((e) => [...e.attributes].some((a) => a.name.startsWith('wire:model') && a.value === 'criarRegraPorItem.0'));
        c?.click();
    });
    await page.waitForNetworkIdle({ idleTime: 600, timeout: 8000 }).catch(() => {});
    await selecionar(page, 'regraContaFixaPorItem.0', 'Aluguel');
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await page.setViewport({ ...vp, height: 3200 });
    await page.waitForNetworkIdle({ idleTime: 800, timeout: 8000 }).catch(() => {});
    await wait(800);
    await limpar(page);
    const faixa = await page.evaluate(() => {
        const topo = [...document.querySelectorAll('h1, h2, h3, p, div')].find((e) => /^Revisar Extrato banc/.test(e.textContent.trim()) && e.children.length < 4);
        const fim = [...document.querySelectorAll('p, div, li')].filter((e) => /^Ao importar/.test(e.textContent.trim()) && e.textContent.length < 900).pop();
        const b = fim ? fim.closest('[class*="rounded"]') || fim : null;
        return { top: topo ? topo.getBoundingClientRect().top + window.scrollY - 24 : 0, bottom: b ? b.getBoundingClientRect().bottom + window.scrollY + 24 : 2000 };
    });
    // No computador, sem a barra lateral: o recorte fica só com a revisão, e o texto sai maior no vídeo.
    const corte = visao === 'desktop' ? 268 : 0;
    fs.mkdirSync(path.join(OUT, visao), { recursive: true });
    await page.screenshot({ path: path.join(OUT, visao, 'revisao.jpg'), type: 'jpeg', quality: 92, clip: { x: corte, y: Math.max(0, faixa.top), width: vp.width - corte, height: Math.min(3100, faixa.bottom - faixa.top) } });
    await page.setViewport(vpTela);
    console.log('ok:', visao, 'revisao', Math.round(vp.width), 'x', Math.round(faixa.bottom - faixa.top));
    return { w: vp.width - corte, h: Math.round(faixa.bottom - faixa.top) };
}

const PASSOS = [
    { nome: 'contas', url: '/contas', botao: '+ Conta', campos: [['Banco', 'Itaú'], ['Saldo atual', '2500,00']] },
    { nome: 'cartao', url: '/contas', botao: '+ Cartão', campos: [['Nome do cartão', 'Itaú Azul'], ['Banco / emissor', 'Itaú'], ['Limite', '8000,00'], ['Dia de fechamento', '25'], ['Dia de vencimento', '5']] },
    { nome: 'conta-fixa', url: '/contas-fixas', botao: '+ Conta fixa', campos: [['Nome', 'Aluguel'], ['Valor', '1800,00'], ['Dia do vencimento', '5'], ['Necessidade', 'Essencial'], ['Categoria', 'Habita']] },
    { nome: 'regra', url: '/regras-de-categorizacao', botao: '+ Regra', campos: [['Descrição contém', 'SUPERMERCADO'], ['Categoria', 'Alimenta']] },
];

(async () => {
    const browser = await puppeteer.launch({ executablePath: CHROME_PATH, headless: 'new' });

    // Site e entrada: sem login, contexto limpo.
    for (const [visao, vp] of Object.entries(VISOES)) {
        const ctx = await browser.createBrowserContext();
        const p = await ctx.newPage();
        await p.setViewport(vp);
        await p.goto(`${BASE}/`, { waitUntil: 'networkidle0' });
        // No celular o "Minha conta" fica no menu do site; abre se existir o botão do menu.
        const caixa = await p.evaluate(() => {
            const e = [...document.querySelectorAll('a')].filter((x) => x.offsetParent !== null).find((x) => /^Minha conta$/.test(x.textContent.trim()));
            if (!e) return null;
            const r = e.getBoundingClientRect();
            return { x: r.x, y: r.y, w: r.width, h: r.height };
        });
        (caixas[visao] ||= {}).site = caixa;
        // O vídeo não fala de preço nem de período de teste: some com o botão do topo da página.
        await p.evaluate(() => [...document.querySelectorAll('a, button')].filter((x) => /dias grátis/.test(x.textContent)).forEach((x) => (x.style.visibility = 'hidden')));
        await snap(p, visao, 'site-a');
        await p.goto(`${BASE}/entrar`, { waitUntil: 'networkidle0' });
        await p.type('#email', 'seu@email.com.br');
        await p.type('#password', 'senhasenha1');
        await snap(p, visao, 'entrar-b');
        await ctx.close();
    }

    // Passos dentro do app (Ana Ribeiro, dado de demonstração).
    for (const [visao, vp] of Object.entries(VISOES)) {
        const ctx = await browser.createBrowserContext();
        const p = await ctx.newPage();
        await p.setViewport(vp);
        await login(p, 'ana@cerne.test', 'password');
        await tema(p);
        for (const passo of PASSOS) {
            await p.goto(`${BASE}${passo.url}`, { waitUntil: 'networkidle0' });
            await limpar(p);
            (caixas[visao] ||= {})[passo.nome] = await caixaDoBotao(p, passo.botao);
            await snap(p, visao, `${passo.nome}-a`);
            await clicar(p, passo.botao);
            for (const [rotulo, valor] of passo.campos) await preencher(p, rotulo, valor);
            await snap(p, visao, `${passo.nome}-b`);
            ((caixas[visao] ||= {}).cards ||= {})[passo.nome] = await snapCard(p, visao, passo.nome, vp);
        }
        (caixas[visao] ||= {}).revisao = await capturarRevisao(p, visao, vp);
        for (const [url, nome] of [['/importar', 'importar'], ['/fluxo-de-caixa', 'fluxo'], ['/painel', 'painel']]) {
            await p.goto(`${BASE}${url}`, { waitUntil: 'networkidle0' });
            await snap(p, visao, nome);
        }
        await ctx.close();
    }

    fs.writeFileSync(path.join(OUT, 'caixas.json'), JSON.stringify(caixas, null, 2));
    await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
