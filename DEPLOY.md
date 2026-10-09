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
* * * * * cd /home/uXXXXXXX/cerne && php artisan queue:work --stop-when-empty --max-time=50 --timeout=280 --tries=3 >> /dev/null 2>&1
```

`--stop-when-empty` e `--max-time=50` garantem que o processo termina antes do próximo cron — sem isso, processos se acumulam até estourar o limite do plano. `--timeout=280` é outra coisa: é quanto tempo UM job pode rodar antes do worker matá-lo à força — o padrão do Laravel é 60s, curto demais pra extração de PDF grande por IA (um extrato de dois meses chegou a levar 3min31s). Sem esse valor, o worker mata a extração no meio, o documento fica preso em "processing" e — pior — o job volta pra fila e roda de novo do zero, podendo dar um resultado diferente da tentativa anterior (a resposta da IA não é 100% determinística). 280s fica um pouco abaixo do `$timeout = 300` declarado em `ProcessDocumentJob`, dando margem pro worker desistir antes do limite do próprio job.

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
php artisan config:clear
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
php artisan config:clear
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

**Por que `config:clear` antes do `migrate`:** um deploy que adiciona um `config/*.php`
novo (aconteceu ao integrar o pacote de push) quebra `migrate`/qualquer comando
seguinte com `Return value must be of type array, null returned` — o
`bootstrap/cache/config.php` da publicação ANTERIOR ainda está em disco e não
tem a chave nova, e o Laravel usa esse cache em vez de reler `config/`. Sem o
`config:clear` explícito aqui, `php artisan config:cache` no fim do bloco reescreve
o cache a partir do config ATUAL, mas tarde demais — os comandos entre o
`git pull` e o `config:cache` já rodaram contra o cache velho e podem falhar
antes de chegar lá.

O que **não** se verifica sozinho:

- [ ] Backup diário rodando
- [ ] `.env` fora do Git (o `deploy.ps1` só confere o que está prestes a ser commitado, não a configuração do servidor)

### Dado de teste nunca sobe

`DevSeeder`, `DemoDataSeeder`, `CashFlowDemoSeeder`, `FixedBillsDemoSeeder`, `InvestmentsDemoSeeder`, `InsuranceGoalsDemoSeeder` e `ConsultantBulkClientsSeeder` só existem como **código** no repositório — os *dados* que eles geram nunca saem do banco local, porque nenhum deploy roda seeder nenhum. Cada um deles também se recusa a rodar sozinho se `APP_ENV=production` (`database/seeders/Concerns/DevOnlySeeder.php`), então mesmo um `--class` errado digitado por engano no servidor não cria conta fake em produção.

## PWA

O app é instalável no celular: o navegador oferece "Adicionar à tela inicial" a partir do manifesto em `/manifest.webmanifest`. Ícones em `public/icons/` (gerados por script — placeholder até haver identidade visual).

O service worker (`public/sw.js`) **cacheia apenas arquivos estáticos** — build do Vite e ícones. Nenhuma resposta do servidor entra no cache: um saldo servido do cache seria um número errado apresentado como certo, e num aparelho compartilhado poderia aparecer depois do logout.

## Importar histórico de academia (opcional, uma vez)

Cerne Saúde › Academia aceita um histórico já estruturado (JSON revisado pela própria pessoa). É comando de servidor porque o arquivo tem dado de saúde — não há tela de upload. Formato em `database/examples/gym-history.example.json`.

```bash
scp -P 65002 -i ~/.ssh/cerne_hostinger historico_treinos.json u165451165@89.117.7.59:~/historico_treinos.json
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan cerne:gym-import ~/historico_treinos.json --email=CONTA_DA_PESSOA --dry-run"
# conferiu o relatório? repita sem --dry-run:
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan cerne:gym-import ~/historico_treinos.json --email=CONTA_DA_PESSOA && rm ~/historico_treinos.json"
```

A conta precisa ter perfil próprio com membro (treino é pessoal). Validação acusa o arquivo inteiro antes de gravar; rodar de novo não duplica (sessão = treino + data). Apague o JSON do servidor depois.

## Carga de apólices de vida a partir de certificados em PDF (consultor)

Para o consultor que recebe os certificados dos clientes (hoje Icatu): `cerne:import-policies` acha o cliente na carteira do consultor, cria ou atualiza a apólice e guarda o PDF em **Documentos**, ligado à apólice (a tela Seguros mostra "Ver apólice (PDF)"). O JSON sai da leitura dos PDFs e carrega CPF: é arquivo de servidor, apague depois. Formato do JSON: comentário em `App\Console\Commands\ImportPolicyCertificates`.

```bash
scp -P 65002 -i ~/.ssh/cerne_hostinger -r pasta_com_pdfs u165451165@89.117.7.59:~/certificados
scp -P 65002 -i ~/.ssh/cerne_hostinger apolices.json u165451165@89.117.7.59:~/apolices.json
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan cerne:import-policies ~/apolices.json --consultant=EMAIL_DO_CONSULTOR --pdf-dir=../certificados"
# conferiu o relatório (simulação, nada gravado)? repita com --apply, depois apague os arquivos:
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan cerne:import-policies ~/apolices.json --consultant=EMAIL_DO_CONSULTOR --pdf-dir=../certificados --apply && rm -rf ~/certificados ~/apolices.json"
```

Só age em clientes **ativos** do consultor. Casamento por CPF, ou por nome (e nascimento quando o cadastro tem); nome só parecido, dois clientes com o mesmo nome ou apólice já cadastrada para outro cliente viram `revisar` e não são aplicados. Apólice existente (mesmo número) é atualizada, não duplicada; rodar de novo não repete apólice nem PDF.

## Unificar dois clientes como casal

Quando duas pessoas foram cadastradas como clientes separados (perfis individuais) e na verdade são um casal: `cerne:merge-couple` faz o perfil da **principal** virar o perfil do casal e move a outra pessoa para dentro dele, como cônjuge. Os dois logins continuam e passam a abrir o mesmo perfil; o vínculo da cônjuge sai da Carteira do consultor (o casal aparece uma vez, pelo vínculo da principal).

```bash
# simulação (padrão): confere tudo e mostra o que mudaria, sem gravar nada
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan cerne:merge-couple EMAIL_DA_PRINCIPAL EMAIL_DA_CONJUGE"
# conferiu? aplique, guardando a cópia para desfazer à mão se for preciso:
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan cerne:merge-couple EMAIL_DA_PRINCIPAL EMAIL_DA_CONJUGE --apply --backup=\$HOME/unificacao_NOME.json"
```

O registro de membro da cônjuge é **movido**, não recriado: tudo que é dela continua dela (lançamentos, apólices, documentos, saúde, privacidade por lançamento). A operação recusa, sem alterar nada, quando: um dos perfis já é de casal ou tem outro membro; a cônjuge tem assinatura própria; ela é cliente de um consultor que não acompanha a principal (ou o vínculo da principal não está ativo); há categoria própria de mesmo nome nos dois perfis; ou há a mesma reserva do casal nos dois. Regras de categorização com o mesmo texto ficam com a da principal. As chaves de perfil e de membro apagam em cascata: por isso o perfil antigo só é excluído depois de conferido que nada sobrou nele.

## Catálogo compartilhado de exercícios (Academia)

Referência genérica (nome, grupo muscular, foto) que qualquer cliente vê ao montar o plano — não é dado de saúde de ninguém, por isso não é por conta (ver `App\Models\GymExerciseCatalog`, padrão `BelongsToProfileOrShared` igual `ExpenseCategory`/`Bank`).

**Dados do catálogo** (nome/grupo/tipo/observação, sem foto) — roda como qualquer seeder, não é automático no deploy:
```bash
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan db:seed --class=GymExerciseCatalogSeeder"
```
Idempotente (`updateOrCreate` por nome) — rodar de novo atualiza texto sem tocar na foto já vinculada.

**Fotos do catálogo** — mesmo esquema do `cerne:gym-link-images` pessoal, mas sem `--email` (o catálogo não tem dono):
```bash
scp -P 65002 -i ~/.ssh/cerne_hostinger -r pasta_com_fotos u165451165@89.117.7.59:~/gym-catalog-images
scp -P 65002 -i ~/.ssh/cerne_hostinger mapping.json u165451165@89.117.7.59:~/mapping.json
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan cerne:gym-catalog-link-images ~/mapping.json --dry-run"
# conferiu? repita sem --dry-run, depois apague os arquivos do servidor
ssh -p 65002 -i ~/.ssh/cerne_hostinger u165451165@89.117.7.59 "cd ~/cerne && php artisan cerne:gym-catalog-link-images ~/mapping.json && rm -rf ~/gym-catalog-images ~/mapping.json"
```
