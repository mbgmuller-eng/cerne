# Cerne — repasse para o desenvolvedor

Este documento entrega o projeto inteiro. Leia até o fim antes de mexer em
qualquer coisa: há três ou quatro decisões aqui que parecem estranhas e têm
motivo, e desfazê-las quebra o app de um jeito silencioso.

As credenciais estão em [`ACESSOS.md`](ACESSOS.md), fora do controle de versão.

---

## 1. O que é o Cerne

Um app de finanças pessoais com camada de consultoria. Um **consultor** atende
vários **clientes**; cada cliente é um *perfil financeiro* que pode ser
individual ou de casal — e num casal, cada cônjuge controla o que o outro
enxerga.

São 9 telas e 30 tabelas, tudo implementado e no ar:

| Tela | Rota |
|---|---|
| Painel do consultor | `/clientes` |
| Visão geral (dashboard do cliente) | `/painel` |
| Fluxo de caixa | `/fluxo-de-caixa` |
| Contas e cartões | `/contas` |
| Contas fixas | `/contas-fixas` |
| Investimentos e reservas | `/investimentos` |
| Seguros | `/seguros` |
| Objetivos | `/objetivos` |
| Importar documento (IA) | `/importar` |
| Privacidade do casal | `/privacidade` |

A especificação original de produto está em
[`cerne-especificacao-tecnica.md`](cerne-especificacao-tecnica.md). **Atenção:**
ela foi escrita para Next.js + Supabase e o app foi construído em Laravel +
MySQL. Use-a como fonte de *regra de negócio*, nunca de arquitetura. Ela também
tem inconsistências conhecidas, corrigidas na implementação — campos duplicados
em dois idiomas (`is_joint` / `is_conjunta`), flags que são o inverso uma da
outra (`is_default` / `is_customizada`) e uma contagem de tabelas que não bate.

## 2. Stack

Laravel 13 · PHP 8.3 · Livewire 4 · Tailwind 4 · Alpine · MySQL 8.4 local,
MariaDB 11.8 em produção.

Duas armadilhas de versão, porque a maior parte do material que você vai achar
na internet (e boa parte do que um LLM "lembra") é de versões anteriores:

- É **Laravel 13**, não 12. `Queueable` mora em `Illuminate\Foundation\Queue\Queueable`.
- É **Livewire 4**, não 3.

Se o agente escrever código que não existe, quase sempre é isso.

## 3. Subir o ambiente local

Pré-requisitos: PHP 8.3, Composer, MySQL 8, Node 20+.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

Abre em `http://localhost:8000`. Contas de demonstração (senha `password` nas
três):

| Papel | E-mail |
|---|---|
| Consultora | `consultor@cerne.test` |
| Cliente — titular | `ana@cerne.test` |
| Cliente — cônjuge | `bruno@cerne.test` |

Entre como **Ana** para ver o app completo, e como **Bruno** para ver a
privacidade do casal em ação — ele não enxerga tudo que ela enxerga, e isso é o
comportamento correto.

Detalhes de ambiente, incluindo registrar o MySQL como serviço do Windows, estão
em [`DEPLOY.md`](../DEPLOY.md).

## 4. Publicar

```powershell
.\deploy.ps1
```

O script empacota com as dependências de produção já resolvidas (hospedagem
compartilhada não é lugar de rodar `composer install`), envia por scp e aplica
no servidor. O `.env` do servidor **nunca** entra no pacote.

Use `.\deploy.ps1 -Seed` para repovoar os dados de demonstração — isso **apaga**
os dados atuais do servidor. Enquanto for ambiente de testes, tudo bem. No dia
em que houver dado real, não use mais.

Depois de publicar, no servidor:

```bash
php artisan cerne:check
```

É um checklist que se verifica sozinho: fuso, cookies, migrations, cron, fila,
permissões de disco, cache. Verde significa instalação sã.

### Os dois crons

Já configurados no hPanel e **testados funcionando**:

```bash
cd ~/cerne && php artisan schedule:run
```
```bash
cd ~/cerne && php artisan queue:work --stop-when-empty --max-time=50 --tries=3
```

Ambos de minuto em minuto. O primeiro dispara contas fixas (03:10), faturas
(03:20) e snapshots de investimento (dia 1, 03:30). O segundo consome a fila —
é ele que processa PDF com IA.

O agendador carimba um batimento por minuto em cache. Se `cerne:check` disser
que o agendador não está batendo, o cron morreu — e as contas fixas vão parar de
nascer sem ninguém perceber até o cliente reclamar.

## 5. O que ainda falta

Nada bloqueia o uso, mas estes itens estão em aberto:

1. **`ANTHROPIC_API_KEY` não está no servidor.** A tela de importar PDF aceita o
   upload e enfileira, mas o job não tem como chamar a API. Coloque a chave no
   `.env` do servidor e rode `php artisan config:cache`. (O usuário decidiu
   configurar isso depois — não é um bug.)
2. **Contas de demonstração ainda existem, com senha `password`, numa URL
   pública.** Foi decisão consciente: os dados são fictícios e o domínio é
   temporário. **Antes de entrar qualquer dado real, apague as três contas** —
   o `cerne:check` já reclama disso e vai continuar reclamando até você remover.
3. **E-mail não sai do servidor.** O `.env` de produção está com
   `MAIL_MAILER=log` — as mensagens caem em `storage/logs/laravel.log` em vez de
   serem enviadas. Isso afeta o **convite de cliente**: o token é gerado e é
   válido, mas o cliente não recebe nada. Enquanto for assim, pegue o link no
   log e mande à mão. Para ativar de verdade, configure SMTP no `.env`.
4. **A senha SSH circulou em texto puro** durante o desenvolvimento. Troque-a.
5. **OpenAI** foi cogitada como alternativa à Anthropic para extração. Nada
   implementado; a camada de extração está isolada em
   `app/Services/Extraction/` e trocar o provedor não encosta no resto do app.

---

## 6. Como pedir alterações ao Claude Code

O projeto vem com um `CLAUDE.md` na raiz. Seu Claude Code carrega esse arquivo
sozinho a cada sessão — ele já sabe as regras estruturais do app. Você não
precisa repeti-las em todo pedido.

O que segue é o que faz diferença de verdade na qualidade do que você recebe.

### Peça o problema, não a solução

O erro mais caro é chegar já com a implementação decidida. Você recebe
exatamente o que pediu, inclusive quando havia um caminho melhor.

> ❌ "Adiciona um campo `total_gasto` na tabela `financial_profiles` e atualiza
> ele toda vez que salvar uma despesa."
>
> ✅ "Preciso mostrar o total gasto do mês no painel do consultor, na listagem de
> clientes. Hoje não tem."

O segundo pedido abre espaço para notar que já existe agregação em SQL sobre
`year`/`month` com índice — e que um campo denormalizado ali criaria um segundo
lugar onde o número pode ficar errado.

### Diga quem enxerga o quê

Este app tem três níveis de visibilidade e é onde mora quase todo bug sutil.
Sempre que o pedido envolver mostrar dado, responda antes:

- Vale para o **titular**, para o **cônjuge**, ou para os dois?
- O **consultor** vê?
- Se é de casal: entra no **consolidado** ou é privado?

> ✅ "Na tela de contas fixas, quero um filtro por responsável. O cônjuge
> secundário só deve ver as contas dele e as conjuntas — as do titular não.
> O consultor vê todas."

### Traga o sintoma inteiro quando for bug

Tela, quem estava logado, o que esperava, o que apareceu. Número errado? Diga o
que apareceu **e** o que deveria aparecer — a diferença entre os dois valores
costuma apontar direto para a causa.

> ✅ "Logado como Ana, em `/painel`, o patrimônio mostra R$ 25.000 mas somando à
> mão dá R$ 33.508,45. A diferença bate com a conta conjunta do Bruno."

Foi assim que um bug real de flags de compartilhamento foi encontrado.

### Exija teste no que é dinheiro ou privacidade

Peça explicitamente:

> "Escreve o teste junto."

Para cálculo financeiro, regra de visibilidade ou rotina agendada, um teste não
é zelo — é o que impede a regressão silenciosa seis meses depois. E rode
`php artisan test` antes de aceitar qualquer entrega: **363 testes, todos
verdes.** Se voltar vermelho, o problema é o código novo.

### Uma coisa por vez

Pedidos empilhados ("arruma o menu, muda a cor, adiciona o filtro e publica")
produzem trabalho pior em todos os itens e ficam impossíveis de revisar. Peça
um, confira, peça o próximo.

### Sobre publicar

O deploy é rápido e reversível, mas é uma ação para fora. Peça quando quiser —
não deixe o agente publicar por conta própria a cada mudança.

> ✅ "Roda os testes e me mostra o resultado. Se estiver verde, publica."

### Quando ele discordar de você, escute antes de insistir

Se o agente disser que um pedido quebra o isolamento entre clientes ou a
privacidade do casal, ele provavelmente está certo — essas duas coisas são a
espinha do app. Peça a alternativa em vez de reafirmar o pedido original. Se
ainda assim você quiser do seu jeito, diga; ele segue.

### Frases que funcionam bem

| Situação | Peça assim |
|---|---|
| Não sabe onde algo está | "Onde fica a lógica de fechamento de fatura?" |
| Quer entender antes de mudar | "Me explica como o preço médio é recalculado, sem alterar nada." |
| Mudança arriscada | "Antes de mexer, me diz o que isso pode quebrar." |
| Não sabe se é bug ou regra | "Bruno não vê as despesas da Ana. Isso é bug ou é a privacidade funcionando?" |
| Tela feia | "O menu do topo está encavalado no mobile. Deixa mais limpo." |

### Comece toda sessão assim

O `CLAUDE.md` cobre o resto:

> "Leia `docs/REPASSE.md` e `CLAUDE.md` antes de começar."

---

## 7. Onde estão as coisas

| Caminho | O que faz |
|---|---|
| `app/Support/ProfileContext.php` | Perfil ativo da requisição |
| `app/Models/Concerns/BelongsToProfile.php` | Global scope que isola os clientes |
| `app/Models/Concerns/HasSharingFlags.php` | Normaliza as flags de casal |
| `app/Policies/ProfilePolicy.php` | Dono / cônjuge / consultor |
| `app/Services/InstallmentService.php` | Motor de parcelamento |
| `app/Services/InvestmentTransactionService.php` | Preço médio, escala 6 |
| `app/Services/DashboardService.php` | Agregações do painel |
| `app/Services/Extraction/` | Anthropic + structured output |
| `app/Console/Commands/ProductionCheck.php` | `php artisan cerne:check` |
| `routes/console.php` | Rotinas agendadas |
| `database/seeders/DevSeeder.php` | Dados de demonstração |
| `deploy.ps1` | Publicação |
