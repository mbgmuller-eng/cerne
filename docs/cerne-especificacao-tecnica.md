# Cerne — Especificação Técnica para Implementação
**Stack:** Next.js + Supabase (Postgres + Auth + Storage) + Vercel + Anthropic API
**Domínio:** cerne.app.br
**Formato:** PWA (sem app store)
**Versão do modelo de dados:** 2.0 (27 tabelas)

Este documento contém apenas escopo técnico: schema de banco, telas, regras de negócio funcionais e integrações. Decisões comerciais (pricing, jurídico, CNPJ) foram deixadas fora propositalmente.

---

## 1. Mapa de entidades (27 tabelas)

```
AUTENTICAÇÃO & ACESSO
├── users
├── subscriptions
└── consultant_clients

PERFIL FINANCEIRO
├── financial_profiles
├── profile_members
└── profile_access_settings

CONTAS BANCÁRIAS
└── bank_accounts

CARTÕES DE CRÉDITO
├── credit_cards
└── credit_card_invoices

RECEITAS
├── income_categories
└── income_records

DESPESAS
├── expense_categories
├── expense_subcategories
├── expense_records
└── installment_groups

CONTAS FIXAS
├── fixed_bills
└── fixed_bill_payments

INVESTIMENTOS
├── investor_profiles
├── recommended_allocations
├── investment_records
├── investment_snapshots
├── investment_transactions
└── investment_performance

RESERVAS
└── financial_reserves

SEGUROS
└── insurance_policies

SONHOS & OBJETIVOS
└── goals

DOCUMENTOS
└── document_uploads

AUDIT LOG
└── audit_logs

CONFIGURAÇÕES
└── payment_methods
```

---

## 2. Autenticação & Acesso

### `users`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | PK |
| email | VARCHAR(255) | ✅ | Único |
| name | VARCHAR(255) | ✅ | |
| role | ENUM | ✅ | `admin` / `consultant` / `client` |
| avatar_url | TEXT | ❌ | |
| phone | VARCHAR(20) | ❌ | |
| is_active | BOOLEAN | ✅ | Soft delete |
| email_verified_at | TIMESTAMP | ❌ | |
| last_login_at | TIMESTAMP | ❌ | |
| created_at / updated_at | TIMESTAMP | ✅ | |

### `subscriptions`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| user_id | UUID FK users | ✅ | |
| plan | ENUM | ✅ | `free` / `basic` / `premium` / `consultant` |
| status | ENUM | ✅ | `active` / `trialing` / `cancelled` / `past_due` |
| started_at | DATE | ✅ | |
| expires_at | DATE | ❌ | |
| cancelled_at | TIMESTAMP | ❌ | |
| external_subscription_id | VARCHAR | ❌ | ID no gateway |
| created_at | TIMESTAMP | ✅ | |

### `consultant_clients`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| consultant_id | UUID FK users | ✅ | role=consultant |
| client_id | UUID FK users | ✅ | role=client |
| status | ENUM | ✅ | `active` / `pending` / `inactive` |
| invited_at | TIMESTAMP | ✅ | |
| accepted_at | TIMESTAMP | ❌ | |
| notes | TEXT | ❌ | |
| created_at | TIMESTAMP | ✅ | |

Índice único: `(consultant_id, client_id)`

### `consultant_invites` (fluxo de convite de cliente)
Tabela referenciada nas decisões de produto — usa token com expiração de 7 dias. Campos mínimos: `id`, `consultant_id` (FK users), `client_name`, `client_email`, `token` (único), `expires_at`, `status` (`pending`/`accepted`/`expired`), `created_at`.

---

## 3. Perfil financeiro & membros

### `financial_profiles`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| owner_user_id | UUID FK users | ✅ | |
| profile_name | VARCHAR(100) | ✅ | |
| profile_type | ENUM | ✅ | `single` / `couple` / `family` |
| base_currency | VARCHAR(3) | ✅ | Default `BRL` |
| reference_month | INTEGER | ✅ | 1–12 |
| created_at / updated_at | TIMESTAMP | ✅ | |

### `profile_members`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| user_id | UUID FK users | ❌ | Obrigatório se o membro tem login próprio |
| name | VARCHAR(100) | ✅ | |
| role | ENUM | ✅ | `primary` / `secondary` |
| color_hex | VARCHAR(7) | ❌ | |
| is_active | BOOLEAN | ✅ | |
| created_at | TIMESTAMP | ✅ | |

Regra: quando `profile_type = couple`, cada membro deve ter `user_id` preenchido (login individual).

### `profile_access_settings` — privacidade do casal (granular por domínio)
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| expense_visibility | ENUM | ✅ | `own_only` / `all_members` |
| income_visibility | ENUM | ✅ | `own_only` / `all_members` |
| investment_visibility | ENUM | ✅ | `own_only` / `all_members` |
| bank_account_visibility | ENUM | ✅ | `own_only` / `all_members` |
| credit_card_visibility | ENUM | ✅ | `own_only` / `all_members` |
| insurance_visibility | ENUM | ✅ | `own_only` / `all_members` |
| can_edit_partner_records | BOOLEAN | ✅ | |
| updated_by_user_id | UUID FK users | ✅ | |
| updated_at | TIMESTAMP | ✅ | |

**Modos de onboarding (atalhos de UI):**
- 🔓 Transparente → tudo `all_members`, `can_edit_partner_records=true`
- 🔒 Privado → tudo `own_only`, `can_edit_partner_records=false`
- ⚙️ Personalizado → campo a campo

Regra: o consultor **sempre** vê tudo, independente dessas configurações.

---

## 4. Contas bancárias e cartões

### `bank_accounts`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ✅ | Titular |
| bank_name | VARCHAR(100) | ✅ | |
| account_type | ENUM | ✅ | `checking` / `savings` / `digital_wallet` / `investment_account` |
| agency | VARCHAR(20) | ❌ | |
| account_number | VARCHAR(30) | ❌ | |
| current_balance | DECIMAL(15,2) | ✅ | |
| is_joint | BOOLEAN | ✅ | Conta conjunta do casal |
| is_conjunta | BOOLEAN | ✅ | Flag de privacidade — trava as duas abaixo em `true` |
| visivel_para_conjuge | BOOLEAN | ✅ | |
| incluida_no_consolidado | BOOLEAN | ✅ | Exige `visivel_para_conjuge = true` |
| color_hex | VARCHAR(7) | ❌ | |
| is_active | BOOLEAN | ✅ | |
| notes | TEXT | ❌ | |
| created_at / updated_at | TIMESTAMP | ✅ | |

Regra: quando `is_joint=true`, ambos os membros veem a conta independente de `profile_access_settings`.

### `credit_cards`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ✅ | Titular |
| card_name | VARCHAR(100) | ✅ | |
| bank_name | VARCHAR(100) | ✅ | |
| card_brand | ENUM | ✅ | `visa` / `mastercard` / `elo` / `amex` / `hipercard` / `other` |
| credit_limit | DECIMAL(15,2) | ✅ | |
| closing_day | INTEGER | ✅ | 1–31 |
| due_day | INTEGER | ✅ | 1–31 |
| last_four_digits | VARCHAR(4) | ❌ | |
| is_conjunta / visivel_para_conjuge / incluida_no_consolidado | BOOLEAN | ✅ | Mesmas flags e regras de `bank_accounts` |
| color_hex | VARCHAR(7) | ❌ | |
| is_active | BOOLEAN | ✅ | |
| created_at / updated_at | TIMESTAMP | ✅ | |

### `credit_card_invoices` (faturas)
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| credit_card_id | UUID FK credit_cards | ✅ | |
| year / month | INTEGER | ✅ | Competência |
| closing_date | DATE | ✅ | |
| due_date | DATE | ✅ | |
| total_amount | DECIMAL(15,2) | ✅ | |
| status | ENUM | ✅ | `open` / `closed` / `paid` / `overdue` |
| paid_at | DATE | ❌ | |
| paid_amount | DECIMAL(15,2) | ❌ | |
| paid_from_account_id | UUID FK bank_accounts | ❌ | |
| source_document_id | UUID FK document_uploads | ❌ | |
| created_at | TIMESTAMP | ✅ | |

Índice único: `(credit_card_id, year, month)`

**Regra de parcelamento (importante):** compras parceladas geram **uma transação por parcela por ciclo de fatura** (não um lançamento único de valor total), vinculadas por `compra_id`/`installment_group_id`. Distribuição automática nas faturas futuras via `installment_groups.credit_card_id`.

---

## 5. Receitas

### `income_categories`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ❌ | Null = padrão do sistema |
| name | VARCHAR(100) | ✅ | |
| icon | VARCHAR(50) | ❌ | |
| is_default | BOOLEAN | ✅ | Não pode ser deletada |
| is_active | BOOLEAN | ✅ | |
| sort_order | INTEGER | ❌ | |

Seed padrão: Salário, Bônus, VR/VA, Reembolsos, Restituição IR, Vale Combustível, Aluguéis, Dividendos, Participação de Lucros.

### `income_records`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ❌ | Null = receita da família |
| category_id | UUID FK income_categories | ✅ | |
| description | VARCHAR(255) | ❌ | |
| amount | DECIMAL(15,2) | ✅ | |
| received_date | DATE | ✅ | |
| year / month | INTEGER | ✅ | Desnormalizado p/ performance |
| bank_account_id | UUID FK bank_accounts | ❌ | |
| is_recurring | BOOLEAN | ✅ | |
| notes | TEXT | ❌ | |
| source_document_id | UUID FK document_uploads | ❌ | |
| created_by_user_id | UUID FK users | ✅ | |
| created_at / updated_at | TIMESTAMP | ✅ | |

---

## 6. Despesas & parcelamentos

### `expense_categories`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ❌ | Null = padrão |
| name | VARCHAR(100) | ✅ | |
| icon / color_hex | — | ❌ | |
| is_default / is_active | BOOLEAN | ✅ | |
| sort_order | INTEGER | ❌ | |

**Importante:** `necessity_type` **não** fica na categoria — fica no lançamento (`expense_records.necessity`), pois um mesmo item pode ser Essencial ou Supérfluo dependendo do lançamento. Subcategorias são uma lista única por categoria, independente da necessidade.

**Regra de "sem Outros":** nenhuma categoria tem subcategoria fallback "Outros" travada — o usuário cria subcategoria customizada na hora, marcada com `is_customizada=true`, vinculada ao `profile_id`.

### `expense_subcategories`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| category_id | UUID FK expense_categories | ✅ | |
| profile_id | UUID FK financial_profiles | ❌ | Null = padrão do sistema |
| name | VARCHAR(100) | ✅ | |
| is_default | BOOLEAN | ✅ | |
| is_customizada | BOOLEAN | ✅ | True = criada pelo usuário |
| is_active | BOOLEAN | ✅ | |

### `expense_records`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ❌ | Null = gasto da família |
| description | VARCHAR(255) | ✅ | |
| necessity | ENUM | ✅ | `essential` / `discretionary` / `investment` |
| category_id | UUID FK expense_categories | ✅ | |
| subcategory_id | UUID FK expense_subcategories | ❌ | |
| amount | DECIMAL(15,2) | ✅ | |
| expense_date | DATE | ✅ | |
| year / month | INTEGER | ✅ | Desnormalizado |
| bank_account_id | UUID FK bank_accounts | ❌ | Se débito |
| credit_card_id | UUID FK credit_cards | ❌ | Se crédito |
| credit_card_invoice_id | UUID FK credit_card_invoices | ❌ | |
| installment_group_id | UUID FK installment_groups | ❌ | Null = pagamento único |
| installment_number | INTEGER | ❌ | Ex: 2 de 10 |
| notes | TEXT | ❌ | |
| source_document_id | UUID FK document_uploads | ❌ | |
| created_by_user_id | UUID FK users | ✅ | |
| created_at / updated_at | TIMESTAMP | ✅ | |

### `installment_groups`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| description | VARCHAR(255) | ✅ | |
| total_amount | DECIMAL(15,2) | ✅ | |
| total_installments | INTEGER | ✅ | |
| installment_amount | DECIMAL(15,2) | ✅ | |
| first_installment_date | DATE | ✅ | |
| credit_card_id | UUID FK credit_cards | ❌ | |
| created_by_user_id | UUID FK users | ✅ | |
| created_at | TIMESTAMP | ✅ | |

---

## 7. Contas fixas

### `fixed_bills`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ❌ | Null = conta da família |
| name | VARCHAR(255) | ✅ | |
| amount | DECIMAL(15,2) | ✅ | |
| due_day | INTEGER | ✅ | |
| bank_account_id | UUID FK bank_accounts | ❌ | |
| credit_card_id | UUID FK credit_cards | ❌ | |
| category_id / subcategory_id | UUID FK | ❌ | |
| is_variable | BOOLEAN | ✅ | |
| is_active | BOOLEAN | ✅ | |
| notes | TEXT | ❌ | |
| created_at | TIMESTAMP | ✅ | |

### `fixed_bill_payments`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| fixed_bill_id | UUID FK fixed_bills | ✅ | |
| year / month | INTEGER | ✅ | |
| amount_paid | DECIMAL(15,2) | ❌ | |
| status | ENUM | ✅ | `pending` / `paid` / `overdue` / `skipped` |
| paid_at | DATE | ❌ | |
| notes | TEXT | ❌ | |

Índice único: `(fixed_bill_id, year, month)`

---

## 8. Investimentos

### `investor_profiles`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ✅ | |
| investor_type | ENUM | ✅ | `conservative` / `moderate` / `aggressive` / `entrepreneur` |
| monthly_cost_average | DECIMAL(15,2) | ❌ | |
| months_reserve_target | INTEGER | ❌ | |
| updated_at | TIMESTAMP | ✅ | |

### `recommended_allocations`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| investor_profile_id | UUID FK investor_profiles | ✅ | |
| asset_class | ENUM | ✅ | `fixed_income` / `funds` / `equities_fiis` / `digital_assets` / `fx_currencies` / `etfs` / `international` |
| target_percentage | DECIMAL(5,2) | ✅ | |

### `investment_records`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ✅ | |
| sector | ENUM | ✅ | `reserve` / `retirement` / `fixed_income` / `variable_income` / `international` |
| asset_class | ENUM | ✅ | `reserva_paz` / `reserva_oportunidade` / `previdencia` / `cdb` / `tesouro` / `lca` / `lci` / `fundo` / `acao` / `fii` / `etf` / `fundo_infra` / `cripto` / `etf_internacional` / `acao_exterior` / `poupanca` / `consorcio` / `outro` |
| ticker | VARCHAR(20) | ❌ | |
| name | VARCHAR(255) | ✅ | |
| institution | VARCHAR(255) | ❌ | |
| current_amount | DECIMAL(15,2) | ✅ | |
| invested_amount | DECIMAL(15,2) | ❌ | |
| average_price | DECIMAL(15,6) | ❌ | |
| quantity | DECIMAL(15,6) | ❌ | |
| purchase_date / maturity_date | DATE | ❌ | |
| return_rate | VARCHAR(50) | ❌ | |
| return_rate_type | ENUM | ❌ | `prefixed` / `postfixed_cdi` / `postfixed_ipca` / `variable` |
| broker_account_id | UUID FK bank_accounts | ❌ | |
| source | ENUM | ❌ | `manual` / `pdf_import` / `open_finance` (ver seção 11) |
| external_asset_id | VARCHAR | ❌ | ID do ativo na origem externa, usado para upsert |
| is_locked_by_sync | BOOLEAN | ❌ | Usuário destravou edição manual sobre dado sincronizado |
| is_active | BOOLEAN | ✅ | |
| notes | TEXT | ❌ | |
| source_document_id | UUID FK document_uploads | ❌ | |
| created_by_user_id | UUID FK users | ✅ | |
| created_at / updated_at | TIMESTAMP | ✅ | |

### `investment_snapshots`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| investment_id | UUID FK investment_records | ✅ | |
| year / month | INTEGER | ✅ | |
| amount | DECIMAL(15,2) | ✅ | |
| quantity | DECIMAL(15,6) | ❌ | |
| created_at | TIMESTAMP | ✅ | |

Índice único: `(investment_id, year, month)`. Gerado automaticamente dia 1 de cada mês ou ao importar extrato de custódia.

### `investment_transactions`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| investment_id | UUID FK investment_records | ✅ | |
| profile_id / member_id | UUID FK | ✅ | |
| transaction_type | ENUM | ✅ | `buy` / `sell` / `dividend` / `jcp` / `amortization` / `split` / `grouping` / `subscription` |
| quantity | DECIMAL(15,6) | ❌ | |
| unit_price | DECIMAL(15,6) | ❌ | |
| total_amount | DECIMAL(15,2) | ✅ | |
| broker_fee / other_fees | DECIMAL(10,2) | ❌ | |
| net_amount | DECIMAL(15,2) | ✅ | |
| operation_date | DATE | ✅ | |
| settlement_date | DATE | ❌ | D+2 ações, D+1 FIIs |
| source_document_id | UUID FK document_uploads | ❌ | |
| created_by_user_id | UUID FK users | ✅ | |
| created_at | TIMESTAMP | ✅ | |

Regra: a cada `buy`, recalcular `investment_records.average_price` e `quantity`:
```
novo_preco_medio = (qtd_atual × preco_medio_atual + qtd_nova × preco_unitario_novo)
                   ÷ (qtd_atual + qtd_nova)
```

### `investment_performance`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id / member_id | UUID FK | ✅ | |
| investment_id | UUID FK investment_records | ❌ | Null = performance da carteira toda |
| period_type | ENUM | ✅ | `monthly` / `yearly` / `inception` |
| year / month | INTEGER | ✅/❌ | month null se yearly |
| return_amount | DECIMAL(15,2) | ✅ | |
| return_percentage | DECIMAL(8,4) | ✅ | |
| benchmark | ENUM | ❌ | `cdi` / `ipca` / `ibovespa` / `ifix` / `sp500` |
| benchmark_return | DECIMAL(8,4) | ❌ | |
| vs_benchmark | DECIMAL(8,4) | ❌ | |
| institution | VARCHAR(255) | ❌ | |
| source_document_id | UUID FK document_uploads | ❌ | |
| created_at | TIMESTAMP | ✅ | |

Índice único: `(investment_id, period_type, year, month)`

---

## 9. Reservas, Seguros, Objetivos

### `financial_reserves`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id / member_id | UUID FK | ✅ | |
| reserve_type | ENUM | ✅ | `paz` / `oportunidade` |
| target_amount / current_amount | DECIMAL(15,2) | ✅ | |
| linked_investment_id | UUID FK investment_records | ❌ | |
| updated_at | TIMESTAMP | ✅ | |

### `insurance_policies`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ❌ | Null = seguro familiar |
| insurance_type | ENUM | ✅ | `vida` / `carro` / `residencia` / `saude` / `viagem` / `outro` |
| insurer_name | VARCHAR(255) | ✅ | |
| policy_number | VARCHAR(100) | ❌ | |
| coverage_amount | DECIMAL(15,2) | ❌ | |
| monthly_premium | DECIMAL(15,2) | ✅ | |
| annual_premium | DECIMAL(15,2) | ❌ | |
| payment_frequency | ENUM | ✅ | `monthly` / `quarterly` / `annual` |
| bank_account_id | UUID FK bank_accounts | ❌ | |
| start_date / expiry_date | DATE | ✅/❌ | |
| is_active | BOOLEAN | ✅ | |
| beneficiaries | JSONB | ❌ | Lista com percentuais |
| notes | TEXT | ❌ | |
| source_document_id | UUID FK document_uploads | ❌ | |
| created_by_user_id | UUID FK users | ✅ | |
| created_at / updated_at | TIMESTAMP | ✅ | |

**Extensão prevista (seguros de vida):** módulo específico com 3 tabelas satélite — `seguros_vida`, `coberturas_seguro`, `beneficiarios_seguro` — e um enum `categoria_risco` para agregação SUM por categoria de risco no card resumo. `tipo_avaliacao_sinistro` deve ser modelado como 3 entidades distintas (tabela percentual, score clínico tipo IAIF, lista fechada de eventos), pois seguradoras diferentes (ICATU, AZOS) usam modelos de sinistro incompatíveis entre si dentro do mesmo produto.

### `goals`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| member_id | UUID FK profile_members | ❌ | Null = objetivo do casal |
| name | VARCHAR(255) | ✅ | |
| priority | INTEGER | ✅ | 1 = mais prioritário |
| estimated_value | DECIMAL(15,2) | ✅ | |
| target_date | DATE | ❌ | |
| funding_method | ENUM | ✅ | `lump_sum` / `installments` / `investment_return` |
| installment_amount | DECIMAL(15,2) | ❌ | |
| current_amount | DECIMAL(15,2) | ✅ | |
| linked_investment_id | UUID FK investment_records | ❌ | |
| status | ENUM | ✅ | `active` / `achieved` / `cancelled` |
| notes | TEXT | ❌ | |
| created_by_user_id | UUID FK users | ✅ | |
| created_at / updated_at | TIMESTAMP | ✅ | |

---

## 10. Documentos, Audit Log, Configurações

### `document_uploads`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| uploaded_by_user_id | UUID FK users | ✅ | |
| member_id | UUID FK profile_members | ❌ | |
| document_type | ENUM | ✅ | `bank_statement` / `credit_card_invoice` / `investment_statement` / `brokerage_note` / `performance_report` / `insurance_policy` / `income_tax` / `other` |
| original_filename | VARCHAR(255) | ✅ | |
| storage_path | TEXT | ✅ | Supabase Storage |
| institution_name | VARCHAR(255) | ❌ | Identificada pela IA |
| reference_month / reference_year | INTEGER | ❌ | |
| processing_status | ENUM | ✅ | `pending` / `processing` / `completed` / `failed` |
| records_extracted | INTEGER | ❌ | |
| extraction_summary | JSONB | ❌ | |
| error_message | TEXT | ❌ | |
| created_at | TIMESTAMP | ✅ | |

**Fluxo de processamento por tipo:**
```
bank_statement        → income_records + expense_records
credit_card_invoice   → expense_records + credit_card_invoices
investment_statement  → investment_records + investment_snapshots
brokerage_note        → investment_transactions → recalcula average_price
performance_report    → investment_performance
insurance_policy      → insurance_policies
```

### `audit_logs`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| user_id | UUID FK users | ✅ | |
| action | ENUM | ✅ | `created` / `updated` / `deleted` |
| entity_type | VARCHAR(50) | ✅ | Nome da tabela afetada |
| entity_id | UUID | ✅ | |
| old_value / new_value | JSONB | ❌ | |
| ip_address | VARCHAR(45) | ❌ | |
| user_agent | TEXT | ❌ | |
| created_at | TIMESTAMP | ✅ | |

Regras: gravação automática (trigger de banco ou middleware de API — nunca confiar no frontend), append-only, visível apenas ao consultor.

### `payment_methods`
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id | UUID FK financial_profiles | ✅ | |
| type | ENUM | ✅ | `bank_account` / `credit_card` / `cash` / `pix` |
| bank_account_id | UUID FK bank_accounts | ❌ | |
| credit_card_id | UUID FK credit_cards | ❌ | |
| label | VARCHAR(100) | ❌ | |
| is_active | BOOLEAN | ✅ | |

---

## 11. Integração Open Finance (Pluggy) — v2, não bloqueante para MVP

### Novas tabelas

**`open_finance_connections`**
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| profile_id / member_id | UUID FK | ✅ | |
| institution_name | VARCHAR(255) | ✅ | |
| pluggy_item_id | VARCHAR | ✅ | ID da conexão na Pluggy |
| status | ENUM | ✅ | `active` / `pending_reauth` / `error` / `disconnected` |
| last_synced_at | TIMESTAMP | ❌ | |
| created_at | TIMESTAMP | ✅ | |

**`open_finance_sync_log`**
| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| id | UUID | ✅ | |
| connection_id | UUID FK open_finance_connections | ✅ | |
| sync_date | DATE | ✅ | |
| status | ENUM | ✅ | `success` / `partial` / `failed` |
| records_synced | INTEGER | ❌ | |
| error_message | TEXT | ❌ | |
| created_at | TIMESTAMP | ✅ | |

### Mapeamento Pluggy → `investment_records`

| Campo Pluggy | Campo Cerne | Observação |
|---|---|---|
| `type` (MUTUAL_FUND, EQUITY, FIXED_INCOME, SECURITY, COE) | `sector`/`asset_class` | Precisa de tabela de de-para (taxonomia própria da Pluggy) |
| `code`/`isin` | `ticker` | Só renda variável/fundos |
| `name` | `name` | Direto |
| `institution.name` | `institution` | Via connection |
| `balance` | `current_amount` | Direto |
| `amount` | `invested_amount` | Nem sempre vem preenchido |
| `quantity` | `quantity` | Direto |
| `date` | popula `investment_snapshots` | Cada sync gera um snapshot |
| `annualRate`/`rate` | `return_rate` | Texto livre |

Não coberto por Open Finance Brasil: cripto/OTC (BTC/ETH/USDT), ativos de exchanges — continuam manuais.

### Fluxo de sincronização
1. Usuário conecta instituição via widget hospedado Pluggy (OAuth/consentimento) → cria `open_finance_connections`
2. Job diário (dado D-1) chama API Pluggy por conexão ativa
3. Para cada ativo: upsert em `investment_records` via `(connection_id, external_asset_id)`
4. Gera `investment_snapshots` do mês corrente
5. Grava resultado em `open_finance_sync_log`
6. Se `status=pending_reauth`, notifica usuário para reconectar

### Regra de edição de dado sincronizado
Registro com `source=open_finance` é somente leitura na UI por padrão. Se o usuário quiser corrigir um dado que a instituição reporta errado, usa `is_locked_by_sync` para destravar o campo — a partir daí o registro vira `source=manual` e para de ser sobrescrito no próximo sync.

---

## 12. Telas da aplicação (wireframe v2 — 9 telas)

1. **Dashboard de clientes** (visão consultor) — lista de clientes vinculados via `consultant_clients`, com atalho de acesso a cada perfil.
2. **Visão Geral** — dashboard consolidado do perfil: patrimônio líquido, receitas x despesas do mês, evolução, alertas de conta a vencer.
3. **Fluxo de Caixa** — lançamentos de receita/despesa do mês, filtráveis por categoria/necessidade/membro, com toggle consolidado/individual.
4. **Contas & Cartões** — listagem de `bank_accounts` e `credit_cards`, com drill-down: cartão → fatura (`credit_card_invoices`) → lançamentos individuais (`expense_records`).
5. **Investimentos** — três abas: Portfólio (`investment_records` agrupado por classe), Performance (`investment_performance` vs benchmark), Transações (`investment_transactions`).
6. **Seguros** — listagem de `insurance_policies`, com resumo agregado por categoria de risco (SUM).
7. **Objetivos** — lista de `goals` ordenada por prioridade, com progresso (`current_amount`/`estimated_value`).
8. **Importar PDF** — upload para `document_uploads`, status de processamento, resumo do que foi extraído por tipo de documento.
9. **Configurações de privacidade do casal** — UI para `profile_access_settings`, com os três atalhos (Transparente/Privado/Personalizado) e visualização campo a campo.

---

## 13. Taxonomia de categorias e subcategorias (seed de `expense_categories`/`expense_subcategories`)

Necessidade (`essential`/`discretionary`/`investment`) é um campo do **lançamento**, não da categoria — a mesma subcategoria pode ser essencial ou supérflua dependendo do contexto do gasto.

| Categoria | Subcategorias padrão |
|---|---|
| **Habitação** | Aluguel, Condomínio, Prestação da casa, Aluguel vaga, Diarista, Luz, Água, Gás, Celular, Internet, Streamings, Terreno, Manutenção, Móveis, Tv a Cabo, Manutenção Celular, Decoração, Utensílios Domésticos, Eletrodomésticos, Eletroeletrônicos, IPTU |
| **Filhos** | Natação, Escola, Colônia de Férias, Festa Aniversário, Lanche na escola, Play Kids, Contraturno, Ballet, Judô, Tênis, Jogos Internet |
| **Transporte** | Prestação Carro, Combustível, Estacionamento, Uber, Seguro Carro, Licenciamento, Multas, Manutenção, Aluguel de Carro, Assinatura do carro, Lavagem Carro, Guincho Carro, Sem Parar/Pedágio, IPVA |
| **Saúde** | Terapia, Plano de saúde, Psiquiatra, Gineco, Dermatologista, Fisioterapeuta, Médico, Dentista, Nutricionista, Exames, Farmácia |
| **Educação** | Pós-Graduação, Consultoria Financeira, Livros, Cursos, Papelaria |
| **Alimentação** | Supermercado, Padaria/café/Doceria, Almoço/Jantar, Marmita, Delivery, Restaurantes, Vinhos, Doces, Café |
| **Cuidados Pessoais** | Salão, Academia, Crossfit, Manicure |
| **Lazer** | Viagens/Hoteis, Delivery, Restaurantes, Ingresso show, Correios, Presentes, Compras diversas, Bar, Cinema, Sócio Torcedor |
| **Vestuário** | Roupas, Calçados, Meia |
| **Pets** | Ração, Vacina, Vermífugo, Banho e Tosa, Remédios, Petiscos, Brinquedos |
| **Financeiros** | Juros, IOF de Lis, Empréstimo, Tarifa Conta, Seg Cartão, Anuidade, Saques Cx Eletrônico, Pix Diversos |
| **Família** | (subcategorias por membro, ex: nome de cada membro do casal — suporta conceito de "mesada"/allowance individual dentro do orçamento conjunto) |

Todas as categorias/subcategorias acima entram com `is_default=true`. Nenhuma tem "Outros" fixo — a UI deve sempre oferecer "criar subcategoria" que grava `is_customizada=true` vinculada ao `profile_id`.

---

## 14. Regras de segurança e multi-tenancy

- Toda query deve filtrar por `profile_id`.
- Supabase Row Level Security (RLS) em **todas** as tabelas.
- Autorização em toda requisição verifica, nesta ordem:
  1. `user_id` autenticado é `owner_user_id` do `profile_id`?
  2. OU é membro secundário do perfil (casal), respeitando `profile_access_settings`?
  3. OU é consultor vinculado via `consultant_clients` (acesso irrestrito)?
- Nunca commitar credenciais (Supabase keys, Anthropic API key, segredos de sessão) — variáveis de ambiente.

## 15. Notas de implementação gerais

- Campos `year`/`month` desnormalizados existem em `income_records`, `expense_records`, `fixed_bill_payments`, `investment_snapshots` e `investment_performance` propositalmente, para evitar funções de data em queries de dashboard.
- `investment_transactions` já contém todos os dados para um futuro módulo de apuração de IR sobre renda variável — não deve exigir mudança de schema, só lógica de cálculo.
- Ambiente de staging separado de produção (preview deployments por branch, padrão Vercel + Supabase).
