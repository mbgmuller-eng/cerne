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
| `parcelas-de-dividas` | 03:45 | avisa parcela de financiamento, empréstimo ou consórcio a vencer, a em atraso (a quem deve no 1º dia e uma semana depois; ao consultor do cliente uma vez, com uma semana; até 45 dias de atraso) e a data de reajuste da carta do consórcio (índice único por usuário e parcela em `engagement_reminders`, via `ReminderLedger`) |
| `cotacoes-moedas` | 03:00 | cotação de USDT, BTC e ETH em reais (CoinGecko, depois Binance, depois AwesomeAPI) e novo valor em reais dos investimentos nessas moedas (`CurrencyRateService`; índice único `currency_rates(currency, rate_date)`) |
| `snapshots` | dia 1, 03:30 | foto mensal dos investimentos |
| `agenda-saude`, `cuidados-saude` | 03:35 | avisos de saúde |
| cobrança (`lembrete-pix`, `pix-automatico-*`, `nota-fiscal-pendentes`, `lembrete-fim-do-teste`, `aviso-acesso-encerrando`) | 03:35 a 04:00 | assinatura e Asaas |
| `acompanhamento-roteiro` | 06:30 | reavalia os itens automáticos do passo a passo dos clientes com vínculo ativo (só grava o que mudou de estado) |
| `acompanhamento-lembretes` | 07:45 | lembrete de reunião no dia seguinte e de tarefa que vence amanhã ou venceu (só com vínculo ativo; índice único em `engagement_reminders`) |
| `honorarios-previstos` | 04:05 | estende, para cada contrato de honorário ativo, os recebimentos previstos (12 meses à frente; índice único `fee_agreement_id` + `due_on`) |
| `comissoes-de-seguros` | 04:07 | vincula as apólices que cada profissional vendeu e refaz a previsão de comissão e agenciamento (chave `origin_key` única por profissional) |
| `percentual-patrimonio` | 04:09 | refaz a previsão do percentual sobre patrimônio de cada consultor (estimativa com o patrimônio de hoje) |
| `comissoes-de-consorcio` | 04:11 | refaz a previsão da comissão de consórcio dos consultores (percentual da carta nas parcelas combinadas) |
| `academia-revisao-de-treino` | 09:00 | avisa (uma vez por fase, só o dono) que a fase de treino passou do ponto de troca (`GymPhaseReviewService::notifyDue`, livro `engagement_reminders` tipo `review_due`; monta e solta o `ProfileContext` de cada pessoa) |
| `relatorio-de-erros` | 08:10 | e-mail com os erros do dia anterior (`cerne:error-report`, `ErrorDigest`; índice único `error_report_digests(sent_on)`), importa o arquivo de reserva e limpa o antigo |
| `exclusao-de-contas` | 04:20 | apaga as contas cuja carência de 7 dias (pedida em Minha conta) venceu: `DataRetentionService::deleteDueAccounts` → `AccountDeletionService` (conta que falha, ex.: cobrança não cancelada, fica para a rodada seguinte) |
| `retencao-de-dados` | 04:30 | apaga cópia de dados vencida (7 dias) e o PDF original de importação 90 dias depois de confirmado, descartado ou falho (`DataRetentionService::purge`) |
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
- **Acordo de dívida em aberto (`DebtAgreementSimulator`)**: compara até 4 propostas (total, parcelas, desconto sobre o saldo, taxa implícita de parcelar em vez de pagar à vista) e leva a escolhida ao "Registrar acordo". Não grava nada.
- **Consórcio assistido**: a prévia do reajuste (`DebtService::previewAdjustment`) usa a mesma conta do registro; a sugestão de percentual vem do último reajuste registrado ou, sem histórico, do salto visto nas parcelas pagas (`Debt::observedAdjustment`). O lance na contemplação (`DebtSimulator::bid`, base em `Debt::contemplationBase`: extrato ou parcelas em aberto) mostra "baixar a parcela" x "encurtar o prazo" e só preenche o formulário; lance embutido sai da carta.
- **Objetivos** têm cadastro próprio (`GoalsIndex`): avançam pelo dinheiro informado, pelo saldo de um investimento ou pelas parcelas pagas de um consórcio (um consórcio, um objetivo).
- Fora de produção o projeto **proíbe lazy loading** (`Model::shouldBeStrict` em ambiente local): carregue as relações que a view usa (`with`/`loadMissing`).

### Acompanhamento de erros da plataforma

Todo erro que o Laravel reporta (tela, ação do Livewire, fila, rotina agendada) é anotado na hora por `ErrorRecorder`, ligado em `bootstrap/app.php` (`reportable`, sem `stop()`: o log em arquivo
continua). Regras que não se quebram:

- **Agrupado, não empilhado**: o mesmo erro (classe + arquivo + linha = `fingerprint`, índice único) é UMA linha em `error_logs` com contador, mais `error_log_days` (por dia) e
  `error_log_users` (quem bateu). A gravação é uma instrução atômica (`ON DUPLICATE KEY UPDATE`). Erro **resolvido que volta reabre sozinho** (`regressions`, `reopened_at`); ignorado segue ignorado.
- **Nunca dado de cliente**: a mensagem perde e-mail, CPF, número longo e token (`scrub`); consulta SQL entra com `?` e nunca com os valores; a origem é o nome da rota ou o padrão do endereço
  (`/convite/{token}`), nunca o endereço; o usuário é só o id; o corpo da requisição nem é lido; o passo a passo traz só código do Cerne, sem argumentos. `ErrorLog` está em `EXEMPT` do `TenancyCoverageTest`.
- **Nunca derruba quem estava fazendo outra coisa**: qualquer falha ao gravar (inclusive banco fora do ar) vira uma linha em `storage/logs/erros-pendentes.jsonl`, importada na próxima rodada do relatório.
- Não são anotados: validação, login, permissão, 4xx. `ERROR_TRACKING=false` desliga (os testes rodam assim; quem testa o acompanhamento liga em `setUp`).
- **Relatório diário** (`cerne:error-report`, 08:10): cobre ontem, do mais frequente para o menos, com novos e reabertos em destaque; vai para `ERROR_REPORT_EMAILS` (vírgula) ou, sem isso, para os administradores da plataforma.
  Sai uma vez por dia (índice único); se o e-mail falha a reserva do dia é solta para a próxima rodada tentar de novo. Erro resolvido/ignorado some depois de 90 dias parado, aberto depois de 180.
- **Tela** `/admin/erros` (`AdminErrors`, só administrador, `wire:poll.30s`): abertos, resolvidos, ignorados, busca, passo a passo e ações; o menu do administrador mostra quantos estão abertos.

### Privacidade e LGPD

Texto da política em `/privacidade` (rascunho, **sem revisão jurídica**), plano de incidente em `INCIDENTES.md`. Regras que não se quebram:

- **Aceite versionado** (`config cerne.legal.terms_version`, `RequiresCurrentTerms`, grupo `termos` de toda rota autenticada): mudar o texto dos Termos ou da
  Política exige subir a versão, e todo mundo aceita de novo no próximo acesso. Todo caminho que cria conta grava `terms_accepted_at` **e** `terms_version`.
  A `UserFactory` já nasce aceitando; `->withoutLegalConsent()` simula a conta de antes.
- **Saúde exige consentimento específico** (LGPD art. 11; `RequiresHealthConsent`, `users.health_consent_at/version`). Sem ele a área fecha e as rotinas de
  aviso de saúde pulam a pessoa (`hasCurrentHealthConsent()`). Rota nova de Saúde entra no grupo `consentimento-saude`. Quem se passa por outro ("Entrar como") não é barrado.
- **"Entrar como" tem motivo obrigatório e deixa registro** (`ImpersonationLog`, /admin/acessos): quem, quando, por quê, IP. O registro sobrevive à conta e
  perde nome e e-mail dela na exclusão.
- **Campo sensível é cifrado** (`App\Casts\Sensitive`, `APP_KEY`): CPF/CNPJ e o texto da ficha e da agenda de saúde. Lê cifrado ou texto puro (a troca não derruba tela),
  grava sempre cifrado; `cerne:encrypt-sensitive [--dry-run]` cifra o que sobrou (lista em `EncryptSensitiveData::COLUMNS`). **Não dá para filtrar nem ordenar
  por esses campos em SQL**: ordene em PHP. Campo sensível novo: cast + coluna `TEXT` + entrada em `COLUMNS`. Perder a `APP_KEY` é perder esses dados.
- **Cópia dos dados** (`UserDataExporter`, `ExportUserDataJob`, `DataExport`): roda SOB o `ProfileContext` da pessoa, perfil a perfil, com todos os escopos ligados,
  então o privado do cônjuge e a saúde alheia não saem. Zip em disco privado por 7 dias, link assinado **e** dono logado. Um pedido por dia.
- **Exclusão da conta** (`AccountDeletionService`, nunca `$user->delete()` direto): cancela assinatura e débito na Asaas **antes** (se falhar, não apaga), apaga em
  cascata, apaga o que não tem chave estrangeira (notificações, sessões, convites pelo e-mail...), apaga **os arquivos do disco** (que a cascata do banco não alcança)
  só depois de o banco confirmar, e grava em `account_deletions` um registro sem dado pessoal. Em Minha conta tem carência de 7 dias, senha e e-mail digitado; titular
  de perfil compartilhado confirma que o cônjuge perde o perfil. Cônjuge que não é titular só perde o login. Administrador da plataforma não se exclui por aqui.
  **Tabela nova com dado do usuário sem chave estrangeira, ou disco novo com arquivo da pessoa: acrescente em `AccountDeletionService` e em `UserDataExporter`.**

### Pessoa acompanhada e cuidadores (saúde e documentos de quem não usa o app)

Mãe, pai ou quem mais o usuário ajuda a cuidar: um perfil de cuidado (`ProfileType::Care`, `FinancialProfile`) com UM membro sem login. Quem cadastra é o **dono** e paga
(R$ 4,90 por pessoa: a cobrança é a fase 2, ainda não feita; o recurso fica atrás de `CERNE_CARE_ENABLED`, desligado em produção até lá). Quem cuida junto entra como
**cuidador** (`ProfileCaregiver`, leitura ou edição) e não precisa de assinatura nem de perfil próprio. Regras que não se quebram:

- **Perfil de cuidado é invisível a todo código antigo**: `CareProfileScope` (global em `FinancialProfile`) o esconde de toda consulta por dono (carteira do consultor, agenda,
  perfil padrão ao entrar, datas importantes...). Só aparece por `FinancialProfile::withCareProfiles()`. Consulta nova que precise dele diz isso de propósito.
  Consultor e corretor **nunca** abrem esse perfil (a policy nega), mesmo vinculados ao dono.
- **Cuidador = lista de permitidos, fail-closed**: `ProfileScope` só devolve linhas ao cuidador dos models que declaram `visibleToCaregiver()` (as 7 tabelas de saúde por
  `IsCoupleHealthData` e `Document` por `HasDocumentVisibility`); todo o resto (finanças, seguros, academia, taxonomias) devolve zero. `CaregiverTest` varre `app/Models` e trava a
  lista: mudá-la é decisão de privacidade. Segunda trava por rota: `RestrictCareContext` (alias `cuidado`) só deixa abertas as rotas permitidas dentro do perfil de cuidado.
- **Escrita travada no model** (`CaregiverWriteGuard`): leitor não grava nem apaga; editor cria e altera, mas só apaga o que ele mesmo registrou. Todo registro novo guarda
  `created_by_user_id`/`changed_by_user_id` (o cuidador não tem membro no perfil). Quem não pode gravar não pode nem criar linha ao abrir a tela (`HealthCardService::cardFor`).
- **Documentos**: o cuidador vê só o `shared_with_caregivers` (o dono libera); o que ele mesmo cadastra já nasce liberado para todos. Sem pastas (estrutura do dono).
- **Consentimento é o da pessoa acompanhada**, declarado por quem cadastra (`care_consent_*`: ela autorizou, ou ele é o representante legal). Sem a declaração a Saúde dessa pessoa
  fecha e ninguém é avisado (`RequiresHealthConsent`, `HealthReminderRecipients`). O texto da declaração precisa de advogado.
- **Avisos** de consulta e de troca de item vão ao dono e aos cuidadores com `notify_reminders` (a pessoa não lê o app): `HealthReminderRecipients`.
- **Só o dono** convida, muda o nível, revoga e exclui a pessoa (`FinancialProfilePolicy::manageCaregivers`). Revogar corta na hora (o `SetProfileContext` reautoriza a cada requisição).
  Excluir a pessoa ou a conta do dono apaga dados **e arquivos** (`AccountDeletionService::deleteProfile`).
- Tela `/pessoas` (`CareIndex`) é a porta de entrada, sem perfil ativo (quem só cuida pode não ter perfil próprio). Convite: `/convite-cuidador/{token}`, POST puro.

### Investimento em outra moeda (USDT, BTC, ETH)

Tipos de ativo `usdt`, `btc`, `eth` e `brl` (`AssetClass`; `brl` é saldo em reais numa corretora). Regras que não se quebram:

- **`current_amount` é SEMPRE em reais**: toda soma, o painel, a carteira do consultor e o cálculo de honorários leem ele, e somar 0,05 BTC com R$ 1.000 em uma `SUM()` daria um número errado sem aviso. A quantidade na moeda de origem fica em `native_amount` (`DECIMAL(24,10)`, BTC e ETH vêm com 8 casas) e `current_amount` = quantidade × cotação do dia.
- A moeda (`currency`) é consequência da classe e o model a grava no `saving`: nunca se informa à parte.
- **Cotação diária** (`CurrencyRateService`, rotina `cotacoes-moedas` 03:00, antes da foto mensal das 03:30): grava `currency_rates` (uma por moeda por dia, `upsert`) e refaz o valor em reais com **uma `UPDATE` por moeda** (nada de carregar os ativos; derruba o cache do painel de cada perfil afetado). O arredondamento é o mesmo do cadastro (`toBrl` soma meio centavo antes de cortar), para o valor não mudar um centavo só por ser recalculado. Se todas as fontes falharem, ficam as cotações de ontem e o `cerne:check` avisa depois de 3 dias.
- **Filtro "Em reais / Por moeda"** no painel (`Dashboard::exibir`, lembrado na sessão) e no resumo de Investimentos (`currencyView`): `CurrencyBreakdown` e `<x-currency-breakdown>`. Só aparece para quem tem algo em USDT, BTC ou ETH. USDT mostra 2 casas (como dólar), BTC e ETH 8 (`Money::native`).
- **Carga da Onil** (`OnilPositionsImporter`, `cerne:import-onil`, simulação antes de `--apply`): um investimento por moeda com saldo + saque em processamento, instituição "Onil", chave `external_asset_id = onil:{id}:{moeda}` (rodar de novo atualiza e desativa quem zerou). Cliente sem conta é cadastrado por `ClientOnboardingService::createManagedClient` (senha aleatória, **sem e-mail e sem marcar o e-mail como verificado**; a pessoa entra pelo "esqueci a senha"). Conta existente **sem vínculo ativo não é vinculada**: o vínculo depende da autorização dela. Nunca toca no que foi cadastrado à mão. `--merge=origem:destino` soma uma conta em outra (ex.: a empresa na conta do sócio).

- **Base e performance** (`invested_native`, `realized_native`, `realized_amount`; `OnilBasePerformance`): em USDT, BTC e ETH a base e o resgate ficam na moeda e o
  desempenho é medido em **quantidade**, sem o sobe e desce da cotação; `invested_amount` e `realized_amount` (reais) são essas quantidades vezes a cotação do dia,
  refeitas na mesma `UPDATE` diária. **Ganho = valor atual + já resgatado − base** (`unrealizedGain`, `gainPercentage`, `nativePerformance`); quem não tem resgate
  fica como sempre foi. Regra da carga: base = depósitos da moeda (sem depósito, o valor inicial dos contratos); o resultado por fluxo vale quando concorda com a
  performance dos contratos (até 5% da base). Quando não concorda houve **conversão entre moedas**: vale a performance dos contratos e a base é o que fecha a conta; sem
  nenhum dos dois, `sem base` (resultado zero). A linha da carga diz qual regra saiu em cada moeda.
- **Painel da carteira do consultor**: o filtro "Em reais / Por moeda" (`PortfolioOverview::exibir`, sessão `carteira_exibicao`, vale também em Investimentos da carteira) só
  aparece quando algum cliente tem USDT, BTC ou ETH. Patrimônio de cliente e da carteira aparece **inteiro** (`Money::format`), nunca em `Money::compact`. O último mês do
  gráfico é o valor de hoje (o mesmo do cartão, antes das faturas); mês sem foto segue com o último valor conhecido do ativo até a última foto dele (e até hoje, se ainda ativo).

### Recebimentos do profissional (consultor e corretor)

Tela `/recebimentos` (`ProfessionalIncome`, serviço `ProfessionalIncomeService`): quanto o profissional recebe por mês.
O livro é `IncomeEntry` (previsto, recebido ou cancelado; competência = `due_on`, com `year`/`month` por `HasCompetence`)
e o honorário fixo por cliente é `FeeAgreement` (mensal, trimestral, semestral ou anual). Regras:

- **Sigilo**: o dado é do profissional (`professional_id`), nunca do cliente nem do outro profissional do mesmo cliente.
  Os dois models não usam `BelongsToProfile` (estão em `EXEMPT` do `TenancyCoverageTest`, com o motivo) e toda leitura
  passa por `ownedBy()`. Não crie caminho que mostre recebimento a quem não é o dono.
- **Previsto vs recebido**: o Cerne só *prevê*; quem recebe confirma ("Dar baixa", com valor e data reais). Nada vira
  "recebido" sozinho. Cancelar mantém a linha (o índice único do contrato depende dela e o previsto não ressuscita).
- **Contrato gera previsto** (`FeeForecastService`): só do mês atual em diante (histórico se lança à mão, senão tudo nasceria
  "atrasado"); editar ou pausar refaz só o que ainda é previsão; o recebido e o cancelado ficam. Lançamento gerado por
  contrato não se edita nem exclui, só se dá baixa ou se cancela; o manual se edita e exclui.
- Somas em SQL. Contrato de honorário é só do consultor; o corretor usa o livro com lançamentos manuais.
- **Comissão de seguros (fase 2)**: aba "Regras de comissionamento" (`CommissionRules`, `InsuranceCommissionService`,
  `InsuranceCommissionCalculator`). A regra (`CommissionRule`) é por seguradora (nome canônico via `Insurer::canonicalizer()`) e,
  opcionalmente, por tipo de seguro (a do tipo vale mais): percentual do 1º ano, percentual da renovação (vazio = igual ao 1º ano),
  agenciamento (percentual do prêmio do **1º mês**, a primeira parcela, nunca o do ano todo; ou valor fixo) e prazo de pagamento em dias (a seguradora só paga depois que o cliente
  paga a parcela). A comissão é o percentual do ano da apólice sobre **cada parcela** (mensal, trimestral ou anual); o agenciamento só
  existe para apólice que começa do mês atual em diante. Apólice entra em `PolicyCommission` sozinha quando é do corretor
  (`broker_id`) ou nasceu de cotação de lead do profissional; o consultor inclui outras de clientes ativos à mão. Um vínculo desligado
  não é reativado pela rotina. A previsão (`reconcile`) cria o que falta, corrige o valor do previsto e tira o que deixou de valer;
  recebido e cancelado ficam. Lançamento gerado (`origin_key`) só recebe baixa ou cancelamento (`IncomeEntry::isGenerated()`).
- **Percentual sobre patrimônio (fase 3)**: contrato `AssetFeeAgreement` por cliente (`AssetFeeContracts`, na aba Contratos) e regras de
  investimento (`InvestmentRules`, na aba Regras; são `CommissionRule` com `kind = investment`, `type_key` = escopo produto, empresa ou tipo de
  ativo, `annual_percent`). O percentual é **ao ano**; cada cobrança vale `percentual × meses do período ÷ 12` (mensal, trimestral, semestral
  ou anual) sobre o patrimônio de **hoje** (`current_amount` dos investimentos ativos, sem consórcio): é estimativa. Modo "total" = um
  percentual para tudo; modo "regras" = cada produto paga a regra mais específica (produto > empresa > tipo) e o que não tem regra fica fora.
  Os períodos contam da data de início do contrato e cobram no último dia; só valem os que terminam do mês atual em diante e os que o término do
  contrato completa. Cobrança de período já encerrado deixa de ser recalculada (o valor fica o último estimado até dar baixa). Só cliente com
  vínculo ativo gera previsão (ler o patrimônio exige o vínculo). Rotina `percentual-patrimonio` (04:09), chave `origin_key` `asset:{contrato}:{fim}`.
- **Comissão de consórcio (fase 4)**: regra por administradora (`CommissionRule` com `kind = consortium`: `credit_percent` da carta e
  `installment_schedule`, que `ConsortiumSchedule` lê como "2, 4, 6" = partes iguais ou "2:40, 4:30, 6:30" = divisão em %; os centavos que
  sobram ficam na última parcela) e `DebtCommission` ligando o consultor a um consórcio de cliente ativo, **incluído à mão** (o Cerne não
  sabe quem vendeu): `sold` usa a regra da administradora (casada pelo credor do consórcio, sem acento nem maiúscula) e `third_party` só vale
  o percentual informado no vínculo (que também pode trazer parcelas próprias). Cada parte cai no vencimento da parcela (mais o prazo da
  regra), do mês atual em diante; a carta é a de hoje, então reajuste muda a previsão. Consórcio quitado ou de cliente sem vínculo ativo não
  gera previsão. Rotina `comissoes-de-consorcio` (04:11), chave `origin_key` `consortium:{consórcio}:{parcela}`. Componente `ConsortiumCommissions`.
- **Fase seguinte (combinada)**: 5) conferência por extrato de comissão (IA com revisão), ainda não feita.

### Academia: troca de exercício e treino automático

Dado de saúde pessoal (só o dono vê; `IsPersonalHealthData`). Regras que não se quebram:

- **Cada série guarda o exercício que foi feito** (`gym_set_logs.exercise_id`), por isso trocar o exercício de um item do treino
  (`GymPlanService::swapExercise`) **não muda o histórico**. O detalhe de uma sessão **finalizada** é montado pelas séries gravadas
  (`GymSessionRun::historyRows`), nunca pelo plano de hoje: se voltasse a ler o plano, a troca apagaria o que foi feito na tela.
- **Troca definitiva** (no plano ou na sessão): muda o item, mantém séries, repetições, pausa e observação, e vale nas próximas
  vezes. Recusa trocar exercício que já tem série numa sessão em andamento e exercício que já está no treino.
- **Troca "só por hoje"** (`GymSessionSwap`, só na sessão): o plano não muda; `GymSessionService::applySwaps` troca o exercício do item
  **só na memória** (a tela da sessão nunca salva o item). Só antes da primeira série do item; a troca definitiva do mesmo item
  substitui a de hoje.
- **Treino automático** (`GymPlanGenerator` → `GymWorkoutGenerator`, `GymExerciseClassifier`): regras fixas, sem IA e sem gravar nada
  até a pessoa revisar e salvar (`GymPlanService::createPlanFromDraft`, tudo ou nada; encerra a fase ativa). Usa só o catálogo.
  O catálogo não traz "composto/isolado", nível nem equipamento padronizado: o classificador infere por palavra-chave no nome e na
  dica de equipamento (decisão: marcar o catálogo à mão fica para depois). Entradas: objetivo, nível, dias (2 a 6), tempo, onde treina
  e regiões a proteger. É orientação geral, não prescrição: a tela avisa. Exercício de reabilitação ou muito específico nunca entra
  sozinho (`NEVER_AUTO`), condicionamento (salto, corda, burpee) só no bloco de cardio.
- **A fase guarda as respostas do gerador** (`gym_plans.goal/level/days_per_week/session_minutes/equipment_access/restrictions`, preenchidas
  por `createPlanFromDraft`; fase montada à mão fica sem objetivo até a pessoa defini-lo na tela da Academia, `setPlanGoal`).
- **Hora de trocar a fase** (`GymPhaseReviewService::review`, status ok/soon/due/slow): prazo por objetivo (hipertrofia e saúde geral 8 a 12
  semanas, força, emagrecimento e resistência 6 a 8, iniciante +2; sem objetivo, 8 a 12), **ou** metade dos exercícios acompanhados parados
  (platô), **mas** com menos da metade dos treinos previstos o aviso vira "retome a rotina" (`slow`), não "troque". O aviso aparece na
  Academia e vira notificação **uma vez por fase**. Sugerir subir de nível exige tempo e volume (`levelSuggestion`) e nunca troca sozinho.
- **Treino novo pergunta "seus objetivos continuam os mesmos?"** (passo `review` do `GymPlanGenerator`): mostra a fase (tempo, treinos,
  o que evoluiu), as respostas anteriores e a variedade (variar, manter os que evoluíram, tanto faz; vira `avoid`/`keep` no
  `GymGeneratorInput`). "Sim" gera direto; "Mudou" abre o pedido preenchido.
- **Relatório** (`/saude/academia/relatorio`, `GymReport`, `GymReportService`, PDF `pdf/gym-report`): resumo, frequência, destaques e cada
  exercício dentro do seu treino com recorde, variação e gráfico (SVG por `GymReportCharts`). Lê o histórico inteiro até o fim do período
  para comparar com o que veio antes.

- **Biblioteca de exercícios** (`database/data/gym-exercise-library.json`, tradução em português do free-exercise-db, domínio público; criada
  por `GymLibraryImporter` / `cerne:gym-library-import`): dá o **passo a passo** (`gym_exercise_catalog.instructions`, lista de frases) aos exercícios
  que já existiam e cadastra os que não existiam, com as duas fotos. É idempotente (`source_id`), tem `--dry-run`, **nunca troca foto que já
  está lá nem reescreve texto** (só com `--force`) e não toca `notes`. As fotos **não entram no repositório**: vão por `scp` para uma pasta
  e o comando lê `--images=`. O exercício pessoal (`GymExercise`) não guarda ligação com o catálogo, então o "Como fazer" é achado **pelo nome**
  (`GymExerciseCatalog::instructionsFor`, componente `<x-gym.instructions>`). O treino automático só sorteia o que tem `in_auto_workout`: a lista curada e,
  dos importados, só a força comum de academia (`GymLibraryImporter::eligibleForAuto`: categoria força, nível não avançado, composto ou isolado
  conhecido, equipamento do dia a dia, sem variação rara); a troca à mão enxerga tudo. A biblioteca guarda `source_level/mechanic/category`, que o
  gerador usa no lugar das palavras do nome (nível e composto/isolado) nos importados; o `--force` não é preciso para completar a metadata: ela
  só é preenchida quando falta, para nunca desfazer uma escolha do admin.
- **Foco esportivo** (`GymSport`, `GymSeason`; colunas `gym_plans.sport/sport_days/season`): o esporte **não é um objetivo a mais**. O objetivo continua
  dizendo como prescrever (séries, repetições, descanso); o esporte diz **quais exercícios** ganham prioridade (`emphasis`: o maior peso que bater, nunca
  soma), quais **espaços extras** entram no lugar dos últimos do dia (`extraSlots`, revezando entre os treinos), o que sai do "nunca automático" só
  para ele (`unlocks`: rotação externa para quem nada ou joga tênis, antebraço, trenó) e as **orientações** (`tips`, que viram "Orientações da fase").
  Esporte de resistência (corrida, ciclismo, natação) com 2+ vezes por semana, ou qualquer esporte em competição, **não soma bloco de cardio**.
  A temporada limita os dias (fora 6, pré 4, competição 3; esporte 5+ vezes por semana, 3) e, em competição, tira uma série dos exercícios de carga.
  Prazo de troca da fase com esporte: pré-temporada 4 a 6 semanas, o resto 8 a 12. A revisão pergunta a temporada de novo (é o que mais muda). Listas que carregam o catálogo inteiro usam `withoutInstructions()`.

### Calendário (Saúde e agenda do profissional)

Um módulo só desenha o calendário: `<x-calendar>` (mês, semana e dia, no estilo de uma agenda) alimentado por `CalendarBuilder` e
`CalendarEvent`, e controlado pelo trait `HasCalendar` (visão e data na URL; a visão escolhida fica em `users.calendar_view`).

- **Quem usa só traduz o seu model para `CalendarEvent`** (título, horário, cor, linhas de apoio, `shortTitle` para etiqueta apertada)
  e implementa `calendarEvents($inicio, $fim)`. O calendário não conhece Saúde nem consultor. Cor só passa se for `#RRGGBB`.
- **Saúde** (`HealthAppointmentIndex`): consulta e exame com horário, na cor de quem é; item de cuidado como dia inteiro, vazado.
  A privacidade é a da Agenda de Saúde (consultor não vê nada).
- **Agenda do profissional** (`ConsultantCalendar`, `ConsultantAgendaService`, rota `/agenda`): reuniões, prazos de tarefas e retornos de
  leads (`leads.next_action_at`), só do que é do profissional e dos clientes com vínculo ativo. Corretor só vê leads. É só leitura.
- Celular: a grade de horários vira lista por dia e o mês mostra pontos. Classe de layout nova exige `npm run build` (assets versionados).
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
