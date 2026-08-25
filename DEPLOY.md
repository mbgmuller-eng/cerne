# Cerne — Ambiente local e deploy

## Desenvolvimento local

O ambiente roda **nativo no Windows**, sem Docker.

| Componente | Versão | Onde |
|---|---|---|
| PHP | 8.3.33 NTS | `C:\php83` (já no PATH do usuário) |
| Composer | 2.10.2 | `C:\php83\composer.bat` |
| MySQL | 8.4.9 | binários em `C:\Program Files\MySQL\MySQL Server 8.4`, dados em `C:\Users\mbgmu\mysql84` |
| App | Laravel 13 | http://localhost:8000 |

Bancos: `cerne` (dev) e `cerne_test` (testes). Usuário `cerne`, senha `secret`.

### Subir o ambiente

O MySQL foi instalado **sem serviço do Windows** (exigiria elevação). Inicie-o antes de trabalhar:

```bash
"C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --defaults-file="C:\Users\mbgmu\mysql84.ini"
```

Depois, o servidor da aplicação:

```bash
php artisan serve
```

### Registrar o MySQL como serviço (opcional, exige admin)

Para o MySQL subir junto com o Windows, rode uma vez num PowerShell **como administrador**:

```bash
"C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe" --install MySQL84 --defaults-file="C:\Users\mbgmu\mysql84.ini"
```

```bash
net start MySQL84
```

### Dados de desenvolvimento

```bash
php artisan migrate:fresh --force && php artisan db:seed --class=DevSeeder --force && php artisan db:seed --class=DemoDataSeeder --force
```

`DevSeeder` cria as contas de acesso; `DemoDataSeeder` popula o perfil com dados financeiros para as telas terem o que mostrar. Senha de todos: `password`.

| E-mail | Papel |
|---|---|
| consultor@cerne.test | Consultor (vê a tela de clientes) |
| ana@cerne.test | Titular do casal (dona do perfil) |
| bruno@cerne.test | Cônjuge (sujeito à privacidade) |

### Comandos do dia a dia

```bash
php artisan migrate
```

```bash
php artisan test
```

```bash
php artisan queue:work
```

### Alternativa em Docker

Há um `docker-compose.yml` na raiz (PHP-fpm + Nginx com HTTPS + MySQL 8), caso queira um ambiente idêntico ao servidor sem instalar nada. Requer Docker Desktop funcionando: `docker compose up -d` e acesse https://localhost. O certificado auto-assinado fica em `docker/nginx/certs/` e não é versionado — gere o seu com:

```bash
openssl req -x509 -nodes -newkey rsa:2048 -days 825 -keyout docker/nginx/certs/localhost.key -out docker/nginx/certs/localhost.crt -subj "/CN=localhost/O=Cerne Dev" -addext "subjectAltName=DNS:localhost,IP:127.0.0.1"
```

---

## Produção — Hostinger (hospedagem compartilhada)

A hospedagem compartilhada da Hostinger **não roda worker de fila persistente** e **não tem Node.js confiável**. As duas restrições moldam o deploy.

### 1. Estrutura de pastas

A aplicação fica **fora** de `public_html`. No hPanel, aponte o document root do domínio para a pasta `public` do projeto:

```
/home/uXXXXXXX/
├── cerne/            <- o projeto (fora do alcance da web)
│   ├── app/
│   ├── public/       <- document root do domínio aponta AQUI
│   └── ...
└── public_html/      <- não usado pelo Cerne
```

Nunca colocar o Laravel inteiro dentro de `public_html` — isso expõe `.env`, `storage/` e o código-fonte.

### 2. Assets

O build do Vite roda **na máquina do dev**, nunca no servidor:

```bash
npm run build
```

Suba a pasta `public/build/` gerada junto com o deploy. Ela está no `.gitignore` por padrão no Laravel — remova essa linha se o deploy for por Git, ou envie por FTP.

### 3. Cron

Um único cron a cada minuto cobre agendador e fila:

```bash
* * * * * cd /home/uXXXXXXX/cerne && php artisan schedule:run >> /dev/null 2>&1
```

E a fila, também a cada minuto (planos com cron de 5 min funcionam igual, só com mais latência):

```bash
* * * * * cd /home/uXXXXXXX/cerne && php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
```

`--stop-when-empty` e `--max-time=50` garantem que o processo termina antes do próximo cron — sem isso, processos se acumulam até estourar o limite do plano.

### 4. Permissões

```bash
chmod -R 775 storage bootstrap/cache
```

### 5. `.env` de produção

Nunca versionado. Conferir antes de publicar:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://cerne.app.br
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
```

`ANTHROPIC_API_KEY` também vive só aqui.

### 6. Cache de produção

Depois de cada deploy (sequência completa, com o motivo do `--no-scripts` explicado
na seção **Checklist antes de publicar**, mais abaixo):

```bash
composer install --no-dev --optimize-autoloader --no-scripts
php artisan package:discover --ansi
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

### 7. Backup

```bash
0 3 * * * mysqldump -u USER -pSENHA BANCO | gzip > /home/uXXXXXXX/backups/cerne-$(date +\%F).sql.gz
```

Com rotação de 7 dias:

```bash
30 3 * * * find /home/uXXXXXXX/backups -name 'cerne-*.sql.gz' -mtime +7 -delete
```

---

## Checklist antes de publicar

Na máquina de dev, `deploy.ps1` cobre a parte local — testa, builda os assets, confere que nenhum segredo vazou pro build e que `.env`/`docs/ACESSOS.md` não entram no commit, e dá push pra `origin/master`:

```powershell
.\deploy.ps1 -Message "descrição do que mudou"
```

Use `-NoCommit` pra só testar e buildar (revisar o `git status` antes de decidir a mensagem), ou `-SkipPush` pra commitar local sem empurrar ainda.

Ele **não** tem acesso SSH ao servidor — depois do push, no servidor:

```bash
git pull origin master
composer install --no-dev --optimize-autoloader --no-scripts
php artisan package:discover --ansi
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
chmod -R 775 storage bootstrap/cache
php artisan cerne:check --strict
```

**Por que `--no-scripts` + `package:discover` manual:** a hospedagem compartilhada da
Hostinger desabilita `proc_open` no PHP de linha de comando. O hook automático do
Composer (`postAutoloadDump`, que chama `@php artisan package:discover`) roda esse
comando via `Process`/`proc_open` e falha com *"The Process class relies on
proc_open, which is not available on your PHP installation"* — mesmo com
`composer install` tendo funcionado normalmente até ali. Rodar `php artisan
package:discover` direto no shell não tem esse problema, porque aí é o bash chamando
o PHP diretamente, sem o Composer tentar abrir um subprocesso por dentro do PHP.

`cerne:check --strict` confere ambiente, cookies, banco, fila, agendador, importação por IA e caches, e explica o porquê de cada item que falhar — sai com erro, útil para travar uma publicação automática.

O que **não** se verifica sozinho:

- [ ] Backup diário rodando
- [ ] `.env` fora do Git (o `deploy.ps1` só confere o que está prestes a ser commitado, não a configuração do servidor)

### Dado de teste nunca sobe

`DevSeeder`, `DemoDataSeeder`, `CashFlowDemoSeeder`, `FixedBillsDemoSeeder`, `InvestmentsDemoSeeder`, `InsuranceGoalsDemoSeeder` e `ConsultantBulkClientsSeeder` só existem como **código** no repositório — os *dados* que eles geram nunca saem do banco local, porque nenhum deploy roda seeder nenhum. Cada um deles também se recusa a rodar sozinho se `APP_ENV=production` (`database/seeders/Concerns/DevOnlySeeder.php`), então mesmo um `--class` errado digitado por engano no servidor não cria conta fake em produção.

## PWA

O app é instalável no celular: o navegador oferece "Adicionar à tela inicial" a partir do manifesto em `/manifest.webmanifest`. Ícones em `public/icons/` (gerados por script — placeholder até haver identidade visual).

O service worker (`public/sw.js`) **cacheia apenas arquivos estáticos** — build do Vite e ícones. Nenhuma resposta do servidor entra no cache: um saldo servido do cache seria um número errado apresentado como certo, e num aparelho compartilhado poderia aparecer depois do logout.
