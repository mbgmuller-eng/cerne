#Requires -Version 5.1
<#
.SYNOPSIS
    Prepara um deploy do Cerne: testa, builda os assets de producao e
    (opcionalmente) commita e empurra pra origin/master.

.DESCRIPTION
    Roda inteiramente na maquina de dev - a Hostinger nao builda Node de
    forma confiavel (ver DEPLOY.md), e este script nao tem acesso SSH ao
    servidor. Ele deixa o repositorio pronto pra virar deploy; os comandos
    finais (git pull, migrate --force, caches, cerne:check) continuam
    manuais, rodados por voce via SSH - propositalmente, porque envolvem
    migracao de banco de um app financeiro em producao.

    Nunca commita dado de teste/demonstracao: os seeders de dev/demo
    (DevSeeder, DemoDataSeeder, CashFlowDemoSeeder, FixedBillsDemoSeeder,
    InvestmentsDemoSeeder, InsuranceGoalsDemoSeeder,
    ConsultantBulkClientsSeeder) so criam CODIGO versionado - os dados que
    eles gerariam ficam no banco local, nunca no Git, e cada um deles agora
    se recusa a rodar se APP_ENV=production (ver
    database/seeders/Concerns/DevOnlySeeder.php).

.PARAMETER Message
    Mensagem do commit. Obrigatoria a menos que -NoCommit seja passado.

.PARAMETER NoCommit
    So testa e builda; nao commita nem empurra. Use pra revisar o diff
    antes de decidir a mensagem do commit.

.PARAMETER SkipPush
    Commita localmente mas nao da push - pra revisar o commit antes de
    mandar pro GitHub.

.EXAMPLE
    .\deploy.ps1 -Message "Ajusta calculo de patrimonio liquido no painel da carteira"

.EXAMPLE
    .\deploy.ps1 -NoCommit
#>
param(
    [string]$Message,
    [switch]$NoCommit,
    [switch]$SkipPush
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

function Step($texto) {
    Write-Host ""
    Write-Host "==> $texto" -ForegroundColor Cyan
}

function Falhar($texto) {
    Write-Host ""
    Write-Host "ERRO: $texto" -ForegroundColor Red
    exit 1
}

if (-not $NoCommit -and [string]::IsNullOrWhiteSpace($Message)) {
    Falhar "Passe -Message 'descricao do que mudou' ou use -NoCommit pra so testar e buildar."
}

# ---------------------------------------------------------------------
# 1. Testes - verde e obrigatorio (CLAUDE.md), nao desejavel.
# ---------------------------------------------------------------------
Step "Rodando a suite de testes"
php artisan test
if ($LASTEXITCODE -ne 0) {
    Falhar "Testes vermelhos. Conserte antes de preparar o deploy."
}

# ---------------------------------------------------------------------
# 2. Build de producao dos assets (Vite) - a Hostinger nao builda Node.
# ---------------------------------------------------------------------
Step "Buildando assets de producao (npm run build)"
npm run build
if ($LASTEXITCODE -ne 0) {
    Falhar "Build do Vite falhou."
}

# ---------------------------------------------------------------------
# 3. Varredura de segredo nos assets buildados, por garantia.
# ---------------------------------------------------------------------
Step "Conferindo se algum segredo vazou pro build"
$achados = Get-ChildItem "public/build" -Recurse -Include *.js, *.css -ErrorAction SilentlyContinue |
    Select-String -Pattern "ANTHROPIC_API_KEY|sk-ant|DB_PASSWORD" -List
if ($achados) {
    Falhar "Possivel segredo encontrado em public/build/ - confira antes de continuar."
}

if ($NoCommit) {
    Step "NoCommit: parando aqui. Revise 'git status' e rode de novo com -Message quando decidir."
    git status --short
    exit 0
}

# ---------------------------------------------------------------------
# 4. Nunca deixar .env ou credenciais entrarem no commit.
# ---------------------------------------------------------------------
Step "Conferindo se .env / docs/ACESSOS.md nao vao pro commit"
git add -A
$perigosos = git diff --cached --name-only | Select-String -Pattern "^\.env|ACESSOS\.md"
if ($perigosos) {
    git reset
    Falhar "Arquivo sensivel estagiado ($perigosos) - commit abortado. Confira o .gitignore."
}

$temMudanca = git diff --cached --name-only
if (-not $temMudanca) {
    Step "Nada pra commitar - arvore de trabalho ja bate com o ultimo commit."
    exit 0
}

# ---------------------------------------------------------------------
# 5. Commit.
# ---------------------------------------------------------------------
Step "Commitando"
git commit -m $Message
if ($LASTEXITCODE -ne 0) {
    Falhar "git commit falhou."
}

if ($SkipPush) {
    Step "SkipPush: commit feito localmente, sem push. Rode 'git push origin master' quando revisar."
    exit 0
}

# ---------------------------------------------------------------------
# 6. Push.
# ---------------------------------------------------------------------
Step "Empurrando pra origin/master"
git push origin master
if ($LASTEXITCODE -ne 0) {
    Falhar "git push falhou."
}

# ---------------------------------------------------------------------
# 7. O que continua manual - nao temos acesso SSH ao servidor daqui.
# ---------------------------------------------------------------------
Write-Host ""
Write-Host "==> Local pronto. No servidor (Hostinger), via SSH:" -ForegroundColor Green
Write-Host ""
Write-Host "  git pull origin master"
Write-Host "  composer install --no-dev --optimize-autoloader"
Write-Host "  php artisan migrate --force"
Write-Host "  php artisan config:cache; php artisan route:cache; php artisan view:cache"
Write-Host "  chmod -R 775 storage bootstrap/cache"
Write-Host "  php artisan cerne:check --strict"
Write-Host ""
Write-Host "  NUNCA rode DevSeeder / DemoDataSeeder / ConsultantBulkClientsSeeder (ou" -ForegroundColor Yellow
Write-Host "  qualquer *DemoSeeder) em producao - cada um se recusa sozinho se" -ForegroundColor Yellow
Write-Host "  APP_ENV=production, mas nem tente." -ForegroundColor Yellow
