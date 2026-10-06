// Captura screenshots em alta resolução (2x) do app local pra usar nas páginas
// públicas (landing e /profissionais). Usa o Chrome já instalado na máquina via
// puppeteer-core (sem baixar Chromium próprio). Precisa do servidor local em
// 127.0.0.1:8000 e do banco com os dados de demonstração.
//
//   node scripts/capture-marketing-screenshots.cjs                  # tudo
//   node scripts/capture-marketing-screenshots.cjs --only=investimentos,carteira-datas
const puppeteer = require('puppeteer-core');
const path = require('path');

const CHROME_PATH = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, '..', 'public', 'images', 'marketing');

const onlyArg = process.argv.find((a) => a.startsWith('--only='));
const only = onlyArg ? onlyArg.replace('--only=', '').split(',') : null;
const wanted = (name) => only === null || only.includes(name);

async function login(page, email, password) {
    await page.goto(`${BASE}/entrar`, { waitUntil: 'networkidle0' });
    await page.type('#email', email);
    await page.type('#password', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0' }),
        page.click('button[type="submit"]'),
    ]);
}

async function setLightTheme(page) {
    await page.evaluate(() => {
        document.documentElement.classList.remove('dark');
        document.documentElement.dataset.themePreference = 'light';
    });
    // Persiste no servidor igual o botão faria, pra sobreviver a navegações.
    const csrf = await page.$eval('meta[name="csrf-token"]', (el) => el.content);
    await page.evaluate(async (token) => {
        await fetch('/preferencias/tema', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ theme: 'light' }),
        });
    }, csrf);
}

async function snap(page, file, clip) {
    await new Promise((r) => setTimeout(r, 400));
    await page.screenshot({ path: path.join(OUT, `${file}.jpg`), type: 'jpeg', quality: 92, ...(clip ? { clip } : {}) });
    console.log('ok:', file);
}

async function shoot(page, url, name, prepare, clip) {
    if (!wanted(name)) return;
    await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle0' });
    if (prepare) await prepare(page);
    await snap(page, name, clip);
}

// Rola até o título pelo texto (a tela de Investimentos começa com reservas
// de demonstração zeradas; o mais bonito está em "Perfil do investidor").
const scrollToText = (texto) => (page) => page.evaluate((t) => {
    const el = [...document.querySelectorAll('h2, h3, p')].find((e) => e.textContent.trim() === t);
    if (el) {
        el.scrollIntoView();
        window.scrollBy(0, -24);
    }
}, texto);

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME_PATH,
        headless: 'new',
        defaultViewport: { width: 1440, height: 900, deviceScaleFactor: 2 },
    });

    // Cliente final: Ana Ribeiro (Família Ribeiro, dado de demonstração).
    const client = await browser.newPage();
    await login(client, 'ana@cerne.test', 'password');
    await setLightTheme(client);
    await shoot(client, '/painel', 'painel');
    await shoot(client, '/fluxo-de-caixa', 'fluxo-de-caixa');
    await shoot(client, '/investimentos', 'investimentos', scrollToText('Perfil do investidor'));
    await shoot(client, '/seguros', 'seguros');
    await shoot(client, '/documentos', 'documentos');
    await shoot(client, '/saude/academia', 'saude');
    await client.close();

    // Profissional: Marina Alencar (consultora demo, carteira de 41 clientes).
    // Contexto incógnito à parte: a página do cliente acima já deixou cookie
    // de sessão no contexto padrão, e login de novo ali só redirecionaria
    // pra quem já está logado.
    const proContext = await browser.createBrowserContext();
    const pro = await proContext.newPage();
    await pro.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
    await login(pro, 'consultor@cerne.test', 'password');
    await setLightTheme(pro);
    await shoot(pro, '/carteira', 'carteira-consultor');
    await shoot(pro, '/carteira/seguros', 'carteira-seguros');
    await shoot(pro, '/carteira/investimentos', 'carteira-investimentos');
    await shoot(pro, '/carteira/datas-importantes', 'carteira-datas', async (page) => {
        // Período maior e a aba de vencimento de apólice: com 7 dias a lista fica vazia.
        const valores = await page.$$eval('select option', (opts) => opts.map((o) => o.value));
        const maior = valores[valores.length - 1];
        if (maior) await page.select('select', maior);
        await new Promise((r) => setTimeout(r, 1500));
        await page.evaluate(() => {
            const aba = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Vencimento de apólice');
            if (aba) aba.click();
        });
        await new Promise((r) => setTimeout(r, 1500));
    }, { x: 0, y: 0, width: 1440, height: 400 });

    // Cliente aberto pelo profissional: o menu não tem Saúde e a faixa avisa
    // que ele está vendo o perfil "como consultor".
    if (wanted('carteira-cliente')) {
        await pro.goto(`${BASE}/carteira`, { waitUntil: 'networkidle0' });
        await Promise.all([
            pro.waitForNavigation({ waitUntil: 'networkidle0' }),
            pro.evaluate(() => document.querySelectorAll('form[action*="/abrir"]')[1].requestSubmit()),
        ]);
        await pro.goto(`${BASE}/seguros`, { waitUntil: 'networkidle0' });
        await snap(pro, 'carteira-cliente');
    }
    await proContext.close();

    await browser.close();
    console.log('done');
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
