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
`profile_id = null`) usa `BelongsToProfileOrShared`.

### 2. Privacidade do casal é uma camada *acima* do tenancy

`profile_access_settings` decide o que o cônjuge secundário enxerga. Passar no
teste de tenancy não significa passar na privacidade.

- `is_joint = true` → sempre visível para os dois, ignora as settings.
- `expense_visibility = own_only` → o secundário só vê `member_id` dele ou nulo.
- **Consultor vinculado e ativo vê tudo**, inclusive o que é privado entre o casal.

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
`investment_snapshots(investment_id, year, month)`.

### 5. Dado extraído por IA passa por revisão humana

A extração de PDF grava em `extraction_summary` (JSON) e **só vira lançamento
depois que a pessoa confirma na tela**. Não crie caminho que pule essa etapa.

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
routes/console.php                    rotinas agendadas
```

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

363 testes. **Verde é obrigatório**, não desejável. Se algo ficou vermelho, o
conserto é o código novo — não o teste.

Ao mexer em model, rota ou listagem, confira que ainda passam:
`TenancyIsolationTest`, `TenancyCoverageTest`, `MemberPrivacyTest`,
`TaxonomyIsolationTest`.

Para publicar: `php artisan cerne:check` no servidor precisa ficar verde
(exceto contas de demonstração, enquanto elas existirem de propósito).
