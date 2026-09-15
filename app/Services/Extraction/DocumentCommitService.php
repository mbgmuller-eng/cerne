<?php

namespace App\Services\Extraction;

use App\Enums\DocumentType;
use App\Enums\Necessity;
use App\Enums\ProcessingStatus;
use App\Models\CreditCard;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FixedBillPayment;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\RecurringIncomeOccurrence;
use App\Services\FixedBillService;
use App\Services\InvoiceService;
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
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Importação parcial: só quem passou aqui vira lançamento — o resto
     * fica pendente pra uma próxima revisão, sem perder o que já foi
     * decidido. O documento só vira "Importado" quando TODO item extraído
     * tiver um destino (importado aqui, ou excluído via
     * DocumentUpload::excluded_item_indices) — ver isFullyResolved().
     *
     * @param  list<int>  $indicesAceitos  posições dos itens aprovados nesta rodada de revisão
     * @param  array{categoria?: array<int, string>, subcategoria?: array<int, string>, necessidade?: array<int, string>, estorno?: array<int, bool>, fixedBillPayment?: array<int, string>, recurringIncomeOccurrence?: array<int, string>}  $overrides  escolha da revisão (ver CategorizationRuleMatcher/DocumentsIndex), indexada pela MESMA posição do item na extração — na ausência de uma regra que bateu, cai no comportamento de sempre (categoria_sugerida da IA, necessidade essencial)
     * @return int quantos lançamentos foram criados NESTA chamada
     */
    public function commit(DocumentUpload $documento, array $indicesAceitos, string $userId, array $overrides = []): int
    {
        if (! $documento->isAwaitingReview()) {
            throw new RuntimeException('Este documento não está aguardando revisão.');
        }

        $itens = $documento->extractedItems();
        // Nunca reprocessa o que já tem destino — mesmo que a tela mande de
        // novo por engano (ex.: duplo clique), não duplica lançamento nem
        // briga com um item já excluído.
        $indicesAceitos = array_values(array_diff($indicesAceitos, $documento->resolvedItemIndices()));
        // Preserva o índice original (não usa array_values): é a chave que
        // liga cada item ao seu pré-preenchimento em $overrides.
        $aceitos = array_intersect_key($itens, array_flip($indicesAceitos));

        if ($aceitos === []) {
            throw new RuntimeException('Nenhum item pronto foi selecionado para importar.');
        }

        return DB::transaction(function () use ($documento, $aceitos, $overrides, $userId, $itens): int {
            $criados = match ($documento->document_type) {
                DocumentType::BankStatement => $this->bankStatement($documento, $aceitos, $overrides, $userId),
                DocumentType::CreditCardInvoice => $this->creditCardInvoice($documento, $aceitos, $overrides, $userId),
                default => throw new RuntimeException(
                    'A confirmação automática ainda não cobre '.$documento->document_type->label().'.'
                ),
            };

            $importados = array_values(array_unique(array_merge(
                $documento->imported_item_indices ?? [], array_keys($aceitos),
            )));
            $finalizado = count(array_unique(array_merge($importados, $documento->excluded_item_indices ?? []))) >= count($itens);

            $documento->update([
                'imported_item_indices' => $importados,
                'records_extracted' => count($importados),
                'processing_status' => $finalizado ? ProcessingStatus::Committed : ProcessingStatus::Completed,
                'committed_at' => $finalizado ? now() : null,
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
                $estorno = ($overrides['estorno'][$i] ?? false) === true;

                // Estorno não dá baixa em conta fixa — não é um vencimento
                // agendado sendo pago, é um crédito de volta.
                $pagamento = $estorno ? null : $this->fixedBillPaymentFor($overrides['fixedBillPayment'][$i] ?? null);

                if ($pagamento !== null && $pagamento->status->isOutstanding()) {
                    // Mesmo raciocínio do lado receita: casou com uma conta
                    // fixa pendente, dá baixa nela (FixedBillService::pay()
                    // já debita a conta e cria o lançamento) em vez de duplicar.
                    $this->fixedBillService->pay($pagamento, $valor, $data, $userId);
                    $criados++;

                    continue;
                }

                $jaContabilizada = $pagamento !== null;
                // Sinal final vem só da decisão de estorno, não do que a IA
                // leu — mesma magnitude, negada quando marcado (ver
                // creditCardInvoice() e CashFlowIndex).
                $magnitude = ltrim($valor, '-');
                $valorComSinal = $estorno ? '-'.$magnitude : $magnitude;

                ExpenseRecord::create([
                    'member_id' => $documento->member_id,
                    'description' => $item['descricao'] ?? 'Importado',
                    // Sem regra que bateu, necessidade é julgamento humano,
                    // não da IA: o padrão é essencial e o usuário
                    // reclassifica no fluxo de caixa. Estorno não tem
                    // necessidade/categoria — não é um gasto de verdade.
                    'necessity' => $estorno ? null : (isset($overrides['necessidade'][$i]) && $overrides['necessidade'][$i] !== ''
                        ? Necessity::from($overrides['necessidade'][$i])
                        : Necessity::Essential),
                    'is_refund' => $estorno,
                    'category_id' => $estorno ? null : $this->overrideOr($overrides, 'categoria', $i, fn () => $this->expenseCategory($item['categoria_sugerida'] ?? null)),
                    'subcategory_id' => $estorno ? null : $this->overrideOrNull($overrides, 'subcategoria', $i),
                    'amount' => $valorComSinal,
                    'expense_date' => $data,
                    'bank_account_id' => $jaContabilizada ? null : $conta?->id,
                    'source_document_id' => $documento->id,
                    'created_by_user_id' => $userId,
                ]);

                if (! $jaContabilizada) {
                    // Delta de saldo: despesa debita, estorno credita de
                    // volta — a negação numérica de $valorComSinal cobre os
                    // dois casos com a mesma fórmula (ver CashFlowIndex).
                    $conta?->applyToBalance(bcmul($valorComSinal, '-1', Money::SCALE));
                }
            }

            $criados++;
        }

        return $criados;
    }

    /**
     * Fatura: cada linha vira despesa no cartão escolhido no upload (ver
     * DocumentsIndex::enviar()) — sem isso o total da fatura de verdade
     * (CreditCardInvoice::total_amount) nunca refletia o que acabou de ser
     * importado. Sem casamento de conta fixa aqui — este caminho não
     * movimenta conta bancária nenhuma.
     *
     * Estorno (contestação, cashback) entra com o valor negado: a IA já lê
     * o valor negativo nessa linha, e recalculateTotal() soma tudo com
     * SUM(amount) — o negativo abate o total sozinho, sem lógica extra.
     */
    private function creditCardInvoice(DocumentUpload $documento, array $itens, array $overrides, string $userId): int
    {
        $criados = 0;
        $cartao = $documento->credit_card_id !== null ? CreditCard::find($documento->credit_card_id) : null;
        $faturasAfetadas = [];

        foreach ($itens as $i => $item) {
            $data = $this->parseDate($item['data'] ?? null);

            if ($data === null) {
                continue;
            }

            // A decisão de "é estorno?" já veio pronta da revisão — lá
            // (DocumentsIndex::revisar()) que o sinal negativo da IA
            // pré-marca o item; aqui só respeita o que a pessoa confirmou
            // ou corrigiu, sem tentar adivinhar de novo pelo sinal. O sinal
            // final vem só dessa decisão — a magnitude ignora o sinal que a
            // IA deu, senão desmarcar o estorno herdaria um valor negativo.
            $magnitude = ltrim(Money::parse($item['valor'] ?? 0), '-');
            $estorno = ($overrides['estorno'][$i] ?? false) === true;
            $valorComSinal = $estorno ? '-'.$magnitude : $magnitude;

            $fatura = $cartao !== null ? $this->invoiceService->invoiceForPurchase($cartao, $data) : null;

            if ($fatura !== null) {
                $faturasAfetadas[$fatura->id] = $fatura;
            }

            ExpenseRecord::create([
                'member_id' => $documento->member_id,
                'description' => $item['descricao'] ?? 'Importado',
                'necessity' => $estorno ? null : (isset($overrides['necessidade'][$i]) && $overrides['necessidade'][$i] !== ''
                    ? Necessity::from($overrides['necessidade'][$i])
                    : Necessity::Essential),
                'is_refund' => $estorno,
                'category_id' => $estorno ? null : $this->overrideOr($overrides, 'categoria', $i, fn () => $this->expenseCategory($item['categoria_sugerida'] ?? null)),
                'subcategory_id' => $estorno ? null : $this->overrideOrNull($overrides, 'subcategoria', $i),
                'amount' => $valorComSinal,
                'expense_date' => $data,
                'credit_card_id' => $cartao?->id,
                'credit_card_invoice_id' => $fatura?->id,
                'installment_number' => $estorno ? null : ($item['parcela_atual'] ?? null),
                'source_document_id' => $documento->id,
                'created_by_user_id' => $userId,
            ]);

            $criados++;
        }

        foreach ($faturasAfetadas as $fatura) {
            $this->invoiceService->recalculateTotal($fatura);
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
