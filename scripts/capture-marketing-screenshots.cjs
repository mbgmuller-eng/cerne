// Script único, temporário: captura screenshots em alta resolução (2x) do
// app local pra usar na landing page. Usa o Chrome já instalado na máquina
// via puppeteer-core (sem baixar Chromium próprio). Apague depois de usar.
const puppeteer = require('puppeteer-core');
const path = require('path');

const CHROME_PATH = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const BASE = 'http://127.0.0.1:8000';
const OUT = path.join(__dirname, '..', 'public', 'images', 'marketing');

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

async function shoot(page, url, file) {
    await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle0' });
    await new Promise((r) => setTimeout(r, 300));
    await page.screenshot({ path: path.join(OUT, file), type: 'jpeg', quality: 92 });
    console.log('ok:', file);
}

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME_PATH,
        headless: 'new',
        defaultViewport: { width: 1440, height: 900, deviceScaleFactor: 2 },
    });

    // Cliente final — Ana Ribeiro (Família Ribeiro, dado de demonstração).
    const client = await browser.newPage();
    await login(client, 'ana@cerne.test', 'password');
    await setLightTheme(client);
    await shoot(client, '/painel', 'painel.jpg');
    await shoot(client, '/fluxo-de-caixa', 'fluxo-de-caixa.jpg');
    await shoot(client, '/seguros', 'seguros.jpg');
    await shoot(client, '/documentos', 'documentos.jpg');
    await shoot(client, '/saude/academia', 'saude.jpg');
    await client.close();

    // Profissional — Marina Alencar (consultora demo, carteira de 41 clientes).
    // Contexto incógnito à parte: a página do cliente acima já deixou cookie
    // de sessão no contexto padrão, e login de novo ali só redirecionaria
    // pra quem já está logado.
    const proContext = await browser.createBrowserContext();
    const pro = await proContext.newPage();
    await pro.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 });
    await login(pro, 'consultor@cerne.test', 'password');
    await setLightTheme(pro);
    await shoot(pro, '/carteira', 'carteira-consultor.jpg');
    await shoot(pro, '/carteira/seguros', 'carteira-seguros.jpg');
    await proContext.close();

    await browser.close();
    console.log('done');
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
