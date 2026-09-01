<?php

namespace App\Services\Extraction;

use App\Enums\DocumentType;
use App\Enums\Necessity;
use App\Enums\ProcessingStatus;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FixedBillPayment;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\RecurringIncomeOccurrence;
use App\Services\FixedBillService;
use App\Services\RecurringIncomeService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Confirma a importação: transforma os itens revisados em lançamentos.
 *
 * Só roda depois que uma pessoa conferiu a extração na tela. É a fronteira
 * entre "a IA leu isto" e "isto é um dado financeiro do cliente".
 *
 * Tudo numa transação: metade de um extrato importado é pior que nenhum,
 * porque ninguém percebe que faltou.
 */
class DocumentCommitService
{
    public function __construct(
        private readonly FixedBillService $fixedBillService,
        private readonly RecurringIncomeService $recurringIncomeService,
    ) {}

    /**
     * @param  list<int>  $indicesAceitos  posições dos itens aprovados na revisão
     * @param  array{categoria?: array<int, string>, subcategoria?: array<int, string>, necessidade?: array<int, string>, fixedBillPayment?: array<int, string>, recurringIncomeOccurrence?: array<int, string>}  $overrides  escolha da revisão (ver CategorizationRuleMatcher/DocumentsIndex), indexada pela MESMA posição do item na extração — na ausência de uma regra que bateu, cai no comportamento de sempre (categoria_sugerida da IA, necessidade essencial)
     * @return int quantos lançamentos foram criados
     */
    public function commit(DocumentUpload $documento, array $indicesAceitos, string $userId, array $overrides = []): int
    {
        if (! $documento->isAwaitingReview()) {
            throw new RuntimeException('Este documento não está aguardando revisão.');
        }

        $itens = $documento->extractedItems();
        // Preserva o índice original (não usa array_values): é a chave que
        // liga cada item ao seu pré-preenchimento em $overrides.
        $aceitos = array_intersect_key($itens, array_flip($indicesAceitos));

        if ($aceitos === []) {
            throw new RuntimeException('Nenhum item foi selecionado para importar.');
        }

        return DB::transaction(function () use ($documento, $aceitos, $overrides, $userId): int {
            $criados = match ($documento->document_type) {
                DocumentType::BankStatement => $this->bankStatement($documento, $aceitos, $overrides, $userId),
                DocumentType::CreditCardInvoice => $this->creditCardInvoice($documento, $aceitos, $overrides, $userId),
                default => throw new RuntimeException(
                    'A confirmação automática ainda não cobre '.$documento->document_type->label().'.'
                ),
            };

            $documento->update([
                'processing_status' => ProcessingStatus::Committed,
                'records_extracted' => $criados,
                'committed_at' => now(),
            ]);

            return $criados;
        });
    }

    /**
     * Extrato: cada linha vira receita ou despesa, debitando/creditando a
     * conta escolhida no upload — sem isso o saldo da conta nunca
     * refletiria o que o extrato importado já mostra.
     */
    private function bankStatement(DocumentUpload $documento, array $itens, array $overrides, string $userId): int
    {
        $criados = 0;
        $conta = $documento->bankAccount;

        foreach ($itens as $i => $item) {
            $data = $this->parseDate($item['data'] ?? null);

            if ($data === null) {
                continue;
            }

            $valor = Money::parse($item['valor'] ?? 0);

            if (($item['tipo'] ?? null) === 'receita') {
                // Confere o status de novo aqui — não confia no que a
                // revisão viu: se mudou nesse meio-tempo (foi recebida por
                // outro caminho), trata como já contabilizada em vez de
                // arriscar creditar duas vezes.
                $ocorrencia = $this->recurringIncomeOccurrenceFor($overrides['recurringIncomeOccurrence'][$i] ?? null);

                if ($ocorrencia !== null && $ocorrencia->status->isOutstanding()) {
                    // Casou com uma ocorrência de receita recorrente ainda em
                    // aberto: dá baixa nela em vez de criar receita solta —
                    // reaproveita RecurringIncomeService::receive(), que já
                    // credita a conta e grava o lançamento sozinho.
                    $this->recurringIncomeService->receive($ocorrencia, $valor, $data, $userId);
                    $criados++;

                    continue;
                }

                // Já contabilizada (a pessoa forçou a marcação mesmo com o
                // aviso de "já dado baixa"): registra pra manter o extrato
                // completo, mas SEM mexer no saldo de novo — o crédito já
                // aconteceu quando a ocorrência foi recebida.
                $jaContabilizada = $ocorrencia !== null;

                IncomeRecord::create([
                    'member_id' => $documento->member_id,
                    'category_id' => $this->overrideOr($overrides, 'categoria', $i, fn () => $this->incomeCategory($item['categoria_sugerida'] ?? null)),
                    'description' => $item['descricao'] ?? 'Importado',
                    'amount' => $valor,
                    'received_date' => $data,
                    'bank_account_id' => $jaContabilizada ? null : $conta?->id,
                    'source_document_id' => $documento->id,
                    'created_by_user_id' => $userId,
                ]);

                if (! $jaContabilizada) {
                    $conta?->applyToBalance($valor);
                }
            } else {
                $pagamento = $this->fixedBillPaymentFor($overrides['fixedBillPayment'][$i] ?? null);

                if ($pagamento !== null && $pagamento->status->isOutstanding()) {
                    // Mesmo raciocínio do lado receita: casou com uma conta
                    // fixa pendente, dá baixa nela (FixedBillService::pay()
                    // já debita a conta e cria o lançamento) em vez de duplicar.
                    $this->fixedBillService->pay($pagamento, $valor, $data, $userId);
                    $criados++;

                    continue;
                }

                $jaContabilizada = $pagamento !== null;

                ExpenseRecord::create([
                    'member_id' => $documento->member_id,
                    'description' => $item['descricao'] ?? 'Importado',
                    // Sem regra que bateu, necessidade é julgamento humano,
                    // não da IA: o padrão é essencial e o usuário
                    // reclassifica no fluxo de caixa.
                    'necessity' => isset($overrides['necessidade'][$i]) && $overrides['necessidade'][$i] !== ''
                        ? Necessity::from($overrides['necessidade'][$i])
                        : Necessity::Essential,
                    'category_id' => $this->overrideOr($overrides, 'categoria', $i, fn () => $this->expenseCategory($item['categoria_sugerida'] ?? null)),
                    'subcategory_id' => $this->overrideOrNull($overrides, 'subcategoria', $i),
                    'amount' => $valor,
                    'expense_date' => $data,
                    'bank_account_id' => $jaContabilizada ? null : $conta?->id,
                    'source_document_id' => $documento->id,
                    'created_by_user_id' => $userId,
                ]);

                if (! $jaContabilizada) {
                    $conta?->applyToBalance('-'.$valor);
                }
            }

            $criados++;
        }

        return $criados;
    }

    /** Fatura: cada linha vira despesa no cartão. Sem casamento de conta fixa aqui — este caminho não movimenta conta bancária nenhuma. */
    private function creditCardInvoice(DocumentUpload $documento, array $itens, array $overrides, string $userId): int
    {
        $criados = 0;

        foreach ($itens as $i => $item) {
            $data = $this->parseDate($item['data'] ?? null);

            if ($data === null) {
                continue;
            }

            ExpenseRecord::create([
                'member_id' => $documento->member_id,
                'description' => $item['descricao'] ?? 'Importado',
                'necessity' => isset($overrides['necessidade'][$i]) && $overrides['necessidade'][$i] !== ''
                    ? Necessity::from($overrides['necessidade'][$i])
                    : Necessity::Essential,
                'category_id' => $this->overrideOr($overrides, 'categoria', $i, fn () => $this->expenseCategory($item['categoria_sugerida'] ?? null)),
                'subcategory_id' => $this->overrideOrNull($overrides, 'subcategoria', $i),
                'amount' => Money::parse($item['valor'] ?? 0),
                'expense_date' => $data,
                'installment_number' => $item['parcela_atual'] ?? null,
                'source_document_id' => $documento->id,
                'created_by_user_id' => $userId,
            ]);

            $criados++;
        }

        return $criados;
    }

    /** Busca de novo em vez de confiar no que a revisão viu — status pode ter mudado nesse meio-tempo. */
    private function fixedBillPaymentFor(?string $id): ?FixedBillPayment
    {
        return $id !== null ? FixedBillPayment::query()->find($id) : null;
    }

    /** Espelho de fixedBillPaymentFor() do lado receita. */
    private function recurringIncomeOccurrenceFor(?string $id): ?RecurringIncomeOccurrence
    {
        return $id !== null ? RecurringIncomeOccurrence::query()->find($id) : null;
    }

    /** @param array<string, array<int, string>> $overrides */
    private function overrideOr(array $overrides, string $chave, int $i, \Closure $default): string
    {
        $valor = $overrides[$chave][$i] ?? '';

        return $valor !== '' ? $valor : $default();
    }

    /** @param array<string, array<int, string>> $overrides */
    private function overrideOrNull(array $overrides, string $chave, int $i): ?string
    {
        $valor = $overrides[$chave][$i] ?? '';

        return $valor !== '' ? $valor : null;
    }

    /**
     * Casa a sugestão da IA com a taxonomia existente.
     *
     * Não cria categoria nova: a IA sugerindo "Mercado" quando já existe
     * "Alimentação" encheria o cadastro de sinônimos. Sem correspondência,
     * cai numa categoria neutra e o usuário reclassifica.
     */
    private function expenseCategory(?string $sugestao): string
    {
        if (filled($sugestao)) {
            $achada = ExpenseCategory::available()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($sugestao))])
                ->first();

            if ($achada !== null) {
                return $achada->id;
            }
        }

        return ExpenseCategory::available()->firstOrFail()->id;
    }

    private function incomeCategory(?string $sugestao): string
    {
        if (filled($sugestao)) {
            $achada = IncomeCategory::available()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($sugestao))])
                ->first();

            if ($achada !== null) {
                return $achada->id;
            }
        }

        return IncomeCategory::available()->firstOrFail()->id;
    }

    /** Data ilegível derruba o item, não a importação inteira. */
    private function parseDate(?string $valor): ?CarbonImmutable
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
