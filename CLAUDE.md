# Cerne — instruções para o agente

App de finanças pessoais e consultoria financeira. Um consultor acompanha vários
clientes; cada cliente é um **perfil financeiro** que pode ser individual ou de
casal. Português do Brasil em tudo que o usuário lê.

**Stack:** Laravel 13 · PHP 8.3 · MySQL 8.4 (local) / MariaDB 11.8 (produção) ·
Livewire 4 · Tailwind 4 · Alpine.

---

## As cinco regras que não se quebram

Estas não são preferências de estilo. Cada uma existe porque a alternativa
quebra o app de um jeito que os testes comuns não pegam.

### 1. Todo model de domínio usa `BelongsToProfile`

O MySQL não tem RLS. O isolamento entre clientes é feito por *global scope*:
a trait injeta `where profile_id = ?` em toda query.

**Um model novo sem a trait vaza dados financeiros de um cliente para outro.**

`tests/Feature/TenancyCoverageTest.php` varre `app/Models` e falha se algum
model de domínio não estiver coberto. Se ele falhar, adicione a trait — não
adicione o model à lista de exceções sem entender por quê.

Exceções legítimas já existentes: taxonomia compartilhada (categorias padrão com
`profile_id = null`) usa `BelongsToProfileOrShared`. Os dados do profissional que
ainda não são do cliente (`Lead`, `LeadQuote`, `LeadAttachment`, `LeadAlertDigest`,
`LeadSuggestionDismissal`) não têm `profile_id`: o isolamento é por `consultant_id`,
conferido onde o dado é lido (ver `LeadAttachmentController` e `LeadsIndex`), e cada
um está justificado na lista de exceções do `TenancyCoverageTest`.

### 2. Privacidade do casal é uma camada *acima* do tenancy, e é SIMÉTRICA

Cada lançamento decide por si (`is_private`, ver `MemberPrivacyScope`) se fica
oculto do cônjuge — não existe mais uma configuração por casal nem um "dono"
que enxerga tudo por natureza. Passar no teste de tenancy não significa passar
na privacidade.

- `is_joint = true` → sempre visível para os dois, ignora `is_private`.
- Um lançamento com `is_private = true` só é visível a quem é o dono dele
  (`member_id`) — **vale igual pros dois do casal**, titular incluído.
- **Consultor vinculado e ativo vê tudo**, inclusive o que é privado entre o casal.
- `FinancialReserve` não tem `is_private` próprio — ela fica oculta
  automaticamente se o dono tem QUALQUER gasto essencial marcado como oculto
  (ver `ReservePrivacyScope`), porque o total já entregaria o que o gasto
  escondido devia proteger.

Ao mexer em qualquer listagem, pergunte: *isso vaza por agregado?* Um total
consolidado pode revelar um gasto privado por subtração. Por isso o cache do
dashboard tem a identidade de quem pergunta na chave.

### 3. Dinheiro é `DECIMAL`, nunca float

- Banco: `DECIMAL(15,2)`, cast `decimal:2`.
- Preço médio de investimento: escala **6**, com `bcmath` (`InvestmentTransactionService`).
- Somas em SQL (`SUM()`), nunca `->get()->sum()` em coleção — são milhares de
  linhas e a hospedagem compartilhada tem CPU contada.

### 4. Toda rotina agendada é idempotente

O cron da hospedagem compartilhada pode disparar concorrente ou repetido. A
proteção é **índice único**, não `if (existe)` — que perde a corrida.

Já existem em: `credit_card_invoices(credit_card_id, year, month)`,
`fixed_bill_payments(fixed_bill_id, year, month)`,
`investment_snapshots(investment_id, year, month)`,
`leads(consultant_id, automation_key)` (leads de renovação e venda cruzada),
`lead_alert_digests(consultant_id, sent_on)` (resumo diário de leads),
`insurance_policies(source_lead_quote_id)` (uma cotação gera uma apólice).

### 5. Dado extraído por IA passa por revisão humana

A extração de PDF grava em `extraction_summary` (JSON) e **só vira lançamento
depois que a pessoa confirma na tela**. Não crie caminho que pule essa etapa.

Vale para todas as leituras: extrato, fatura, apólice, relatório de outro app e a
simulação em PDF de um lead (`lead_attachments.extraction_summary`, que só vira
cotação quando o formulário pré-preenchido é salvo). Sugestões de casamento
(fatura de cartão, transferência entre contas, conta fixa) também nunca são
automáticas: a linha vem sugerida e a pessoa confirma.

---

## Convenções

| Assunto | Regra |
|---|---|
| Enums | `VARCHAR` + enum PHP com a trait `HasOptions`. Nunca `ENUM` do MySQL. |
| PKs | UUID (`HasUuids`), coluna `CHAR(36)`. |
| Ano/mês | Colunas `year`/`month` desnormalizadas, preenchidas por observer a partir da data. Servem aos índices do dashboard. |
| Soft delete | Flag `is_active` (é o que a spec define), não `SoftDeletes`. |
| Idioma do código | Nomes em inglês, comentários e textos de tela em português. |
| Comentários | Explicam **por que**, não o que. Se o código já diz, não comente. |

## Estrutura

```
app/Support/ProfileContext.php        perfil ativo da requisição
app/Models/Concerns/                  as traits que sustentam tudo
app/Policies/ProfilePolicy.php        dono / cônjuge / consultor
app/Http/Middleware/SetProfileContext.php
app/Services/                         regra de negócio pesada
app/Services/Extraction/              leitura por IA e casamento das linhas importadas
routes/console.php                    rotinas agendadas
```

### Rotinas agendadas (`routes/console.php`)

Todas idempotentes (regra 4). O cron da Hostinger chama `schedule:run` a cada minuto.

| Nome | Quando | O que faz |
|---|---|---|
| `batimento` | todo minuto | marca que o agendador está vivo (o `cerne:check` confere) |
| `contas-fixas`, `receitas-recorrentes`, `faturas` | 03:10 a 03:20 | gera vencimentos e avisos |
| `datas-importantes` | 03:25 | avisos de aniversário e de vencimento de apólice (não avisa apólice que renova sozinha) |
| `snapshots` | dia 1, 03:30 | foto mensal dos investimentos |
| `agenda-saude`, `cuidados-saude` | 03:35 | avisos de saúde |
| cobrança (`lembrete-pix`, `pix-automatico-*`, `nota-fiscal-pendentes`, `lembrete-fim-do-teste`, `aviso-acesso-encerrando`) | 03:35 a 04:00 | assinatura e Asaas |
| `acompanhamento-roteiro` | 06:30 | reavalia os itens automáticos do passo a passo dos clientes com vínculo ativo (só grava o que mudou de estado) |
| `acompanhamento-lembretes` | 07:45 | lembrete de reunião no dia seguinte e de tarefa que vence amanhã ou venceu (só com vínculo ativo; índice único em `engagement_reminders`) |
| `anexos-de-leads` | 04:10 | apaga arquivos de leads perdidos há mais de 12 meses |
| `leads-de-renovacao` | 07:30 | cria lead para apólice que vence (preferências em `users.renewal_lead_*`) |
| `resumo-de-leads` | 08:00 | uma notificação por profissional com o que pede atenção nos leads |
| `documentos-pendentes` | a cada 5 min | despacha leitura por IA dos envios que ficaram na fila sem chave da API |

A leitura por IA roda em fila do banco (`queue:work --stop-when-empty` no cron):
`ProcessDocumentJob` (documentos) e `ExtractLeadQuoteJob` (simulação de lead).

### Acompanhamento (consultor financeiro e cliente)

Reuniões, tarefas, demandas e notas (`Engagement*`). Regras que não se quebram:

- **A área só existe com vínculo ativo** com um consultor financeiro (`EngagementAccess`): cliente sem consultor não a vê, corretor nunca entra.
- **Todo registro pertence a uma relação** (perfil + `consultant_id`) e o `EngagementScope` só o mostra a quem está nessa relação. Encerrar o vínculo **não apaga** nada: o mesmo consultor de volta recupera o histórico; um consultor novo começa do zero.
- **Nota privada** (`is_private`) é só do consultor que a escreveu; nunca vai ao cliente nem ao PDF do plano.
- **Passo a passo (roteiro)**: o consultor monta (etapas e itens, a partir de texto no formato `# etapa` / `- item @critério`); o cliente só acompanha. O roteiro do cliente é uma **cópia** do modelo; mudar o modelo não o altera. Item com `@critério` é marcado pelo app (`EngagementPlanCriteria`, sempre da perspectiva do consultor, para o resultado não mudar conforme quem abre a tela); item sem critério, só o consultor marca. O estado fica gravado em `done_at`. Os **modelos** (`EngagementPlanTemplate`) são do consultor e só ele os vê.
- Quem decide se o cliente recebe o plano em PDF é o **consultor**, ao encerrar o vínculo (`ConsultantLinkService::end`, `plan_delivery`).

### Dinheiro que não vira lançamento

Na revisão de extrato, estes casos **mexem no saldo da conta sem criar receita nem
despesa**, e o rastro fica em colunas JSON de `document_uploads`:

- pagamento de fatura de cartão: dá baixa na fatura cadastrada
  (`InvoiceService::payFromStatement`) ou, sem fatura, só o saldo (`card_payment_log`);
- transferência entre contas do próprio perfil (`transfer_log`).

Criar uma despesa nesses casos conta o mesmo dinheiro duas vezes no fluxo de caixa.

**Ordem de middleware importa:** `SetProfileContext` roda **antes** de
`SubstituteBindings` (ver `bootstrap/app.php`). Invertido, o route-model binding
tenta resolver o model sem contexto de perfil e devolve 404 em tudo.

**Autenticação é POST puro, não Livewire.** `wire:model` não enxerga valor
preenchido por gerenciador de senha; o campo chega vazio no servidor. Há teste
de regressão para isso — não "modernize" o login para Livewire.

## Antes de entregar qualquer mudança

```bash
php artisan test
```

Cerca de 1600 testes (rodam em uns 10 minutos; no Windows, rode em segundo plano).
**Verde é obrigatório**, não desejável. Se algo ficou vermelho, o conserto é o código
novo — não o teste. Para iterar, filtre: `php artisan test --filter=NomeDoTeste`.

Os testes usam o MySQL local (`cerne_test`). Se `php artisan test` falhar com
"connection refused", o servidor local caiu: suba o `mysqld` antes.

Ao mexer em model, rota ou listagem, confira que ainda passam:
`TenancyIsolationTest`, `TenancyCoverageTest`, `MemberPrivacyTest`,
`TaxonomyIsolationTest`.

Para publicar: `php artisan cerne:check` no servidor precisa ficar verde
(exceto contas de demonstração, enquanto elas existirem de propósito).

A sequência de deploy completa (`git pull`, `composer install --no-scripts`,
`package:discover`, `config:clear` **antes** do `migrate`, caches, `cerne:check --strict`)
e o porquê de cada passo estão em `DEPLOY.md`. Só se publica depois de o dono do
projeto autorizar; nunca se grava em produção sem ele pedir.
