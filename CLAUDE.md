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
| Formulários | Campos lado a lado ficam alinhados: o rótulo cabe em **uma linha** (até 38 caracteres) e a explicação vai numa dica abaixo do campo (`<p class="mt-1 text-xs text-slate-400">`). Rótulo comprido na coluna da metade empurra o campo para baixo. `FormLayoutTest` barra o que escapar. |

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
| `parcelas-de-dividas` | 03:45 | avisa parcela de financiamento, empréstimo ou consórcio a vencer e a data de reajuste da carta do consórcio (índice único por usuário e parcela em `engagement_reminders`, via `ReminderLedger`) |
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

### Dívidas (financiamento, empréstimo, consórcio e dívida em aberto)

Módulo `Debt` + `DebtInstallment` + `DebtAdjustment` (reajustes do consórcio). Regras:

- **Cada parcela paga fica ligada a uma despesa** (`debt_installments.expense_id`, único): o dinheiro sai uma vez só, na despesa, e o saldo da dívida cai junto. A despesa é a parcela **inteira**; a divisão amortização/juros/taxas só aparece na tela da dívida. O contrato guarda a classificação **padrão**, mas é a despesa que carrega a categoria de cada pagamento (reclassificar o contrato só vale para as próximas, a menos que se peça).
- **Desfazer** um pagamento solta a ligação e **nunca apaga a despesa**.
- O cronograma vem do contrato (`DebtScheduleCalculator`, bcmath: SAC, Price ou parcelas informadas); a última parcela absorve a sobra de centavos. Saldo devedor e totais em SQL.
- Dívida **não** altera o "Patrimônio investido" do painel: sem cadastro de bens, subtrair um financiamento mostraria um número enganoso. Ela aparece à parte, em "Dívidas".
- **Consórcio antes da contemplação é investimento, não dívida nem patrimônio.** A parcela paga vira despesa na categoria de investimento "Consórcio" (não cria `InvestmentRecord`); o que vale é a **carta** (`credit_amount`), mostrada à parte ("Cartas de consórcio") e fora do total devedor. Objetivo ligado (`goals.linked_debt_id`) avança por **parcelas pagas / total**, não por dinheiro.
- **Reajuste** (`registerAdjustment`): o Cerne avisa na data (`next_adjustment_on`, rotina `parcelas-de-dividas`), mas o percentual do índice é informado por quem registra; sobe carta, parcelas em aberto e o valor do objetivo, e guarda o histórico em `debt_adjustments`.
- **Contemplação** (`contemplate`): quem registra informa o **novo valor da parcela** e **quantas faltam** (lance baixa a parcela; sorteio em parcela flex pode subir muito). As abertas são refeitas, a classificação passa a Habitação/Transporte e o que falta pagar entra no total devedor. As parcelas já pagas continuam como investimento.
- **Dívida em aberto** (`status` open/negotiating) não tem parcela: conta no total devedor pelo `current_balance`, mas fora das parcelas do mês até o **acordo** (`registerAgreement`), que cria as parcelas e guarda valor negociado e desconto.
- **Extrato do consórcio (importação)**: o jeito certo de cadastrar um consórcio é pelo PDF do "Extrato do Consorciado" (`DocumentType::ConsortiumStatement`, `ConsortiumImport` + `ConsortiumImportService`, leitura por IA com revisão antes de gravar, regra 5). O extrato traz o valor realmente pago e a data de cada parcela; parcela vencida sem pagamento vira `DebtInstallmentStatus::Diluted` ("pulada": o valor foi diluído nas seguintes, fica fora do que está em aberto e do que foi pago). `importConsortiumStatement` é idempotente e **nunca mexe em parcela paga pelo Cerne** (com `expense_id`). O PDF é apagado ao confirmar ou descartar (traz CPF e endereço). O cadastro manual continua, com campo de parcelas puladas.
- **Flex**: `flex_percent` (10 a 50) diz quanto a parcela é menor até a contemplação; a estimativa da parcela cheia (`Debt::estimatedFullInstallment`) vem do saldo a pagar do último extrato e é só ordem de grandeza.
- **Decidir (`DebtStrategyService`, `DebtSimulator`)**: "qual quitar primeiro" ordena por taxa (a do contrato, ou a implícita calculada pelas parcelas e o valor tomado) ou por saldo; dívida sem taxa vai para o fim e é marcada. O simulador (antecipação e portabilidade) só existe para contrato com taxa (SAC/Price) e é **estimativa pelo contrato**. Consórcio fica fora do ranking e da projeção de saldo.
- **Imposto de renda (`DebtTaxReportService`)**: saldo em 31/12 por dívida, pelo que o cronograma ainda não tinha quitado na data (parcela em aberto ou paga depois dela; pulada não conta). A data do contrato é `contracted_on`, ou, sem ela, um mês antes do 1º vencimento (estimativa, sinalizada); consórcio só conta depois de contemplado. `creditor_document` guarda o CPF/CNPJ do credor.
- **Carteira do consultor (`ConsultantPortfolioService::debtOverview`)**: dívidas dos clientes com vínculo ativo, somas em SQL; `Debt` está em `PrivacyGovernedModels`.
- **Extrato bancário**: saída que parece parcela de dívida (valor e data perto, ou o credor na descrição) vem **sugerida** (`DebtInstallmentMatcher`) e só vira baixa quando a pessoa confirma; vira a despesa da parcela, ligada a ela, e o saldo da conta cai uma vez só. Estorno e linhas com outro destino (conta fixa, fatura, transferência) ficam de fora.
- Fora de produção o projeto **proíbe lazy loading** (`Model::shouldBeStrict` em ambiente local): carregue as relações que a view usa (`with`/`loadMissing`).

### Acompanhamento (consultor financeiro e cliente)

Reuniões, tarefas, demandas e notas (`Engagement*`). Regras que não se quebram:

- **A área só existe com vínculo ativo** com um consultor financeiro (`EngagementAccess`): cliente sem consultor não a vê, corretor nunca entra.
- **Todo registro pertence a uma relação** (perfil + `consultant_id`) e o `EngagementScope` só o mostra a quem está nessa relação. Encerrar o vínculo **não apaga** nada: o mesmo consultor de volta recupera o histórico; um consultor novo começa do zero.
- **Nota privada** (`is_private`) é só do consultor que a escreveu; nunca vai ao cliente nem ao PDF do plano.
- **Passo a passo (roteiro)**: o consultor monta (etapas e itens, a partir de texto no formato `# etapa` / `- item @critério`); o cliente só acompanha. O roteiro do cliente é uma **cópia** do modelo; mudar o modelo não o altera. Item com `@critério` é marcado pelo app (`EngagementPlanCriteria`, sempre da perspectiva do consultor, para o resultado não mudar conforme quem abre a tela); item sem critério, só o consultor marca. O estado fica gravado em `done_at`. Os **modelos** (`EngagementPlanTemplate`) são do consultor e só ele os vê.
- **Anexos** (`EngagementAttachment`) são **referência** a um documento da área de Documentos, nunca cópia: só se anexa o que o consultor também pode ver (`EngagementDocuments::shareable`, que respeita o `DocumentVisibilityScope`: apólice e "outros" marcado como visível ao profissional; documento pessoal ou de saúde nunca). Anexar não abre nada que estava fechado.
- **Reunião recorrente**: `scheduleSeries` cria N reuniões comuns ligadas por `series_id` (permite cancelar "esta e as próximas"). **Agenda**: `.ics` por reunião e por prazo de tarefa (`EngagementCalendarController`).
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
