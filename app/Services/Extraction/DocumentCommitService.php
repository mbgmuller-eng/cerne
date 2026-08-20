<?php

namespace App\Services\Extraction;

use App\Enums\DocumentType;
use App\Enums\Necessity;
use App\Enums\ProcessingStatus;
use App\Models\DocumentUpload;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
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
    /**
     * @param  list<int>  $indicesAceitos  posições dos itens aprovados na revisão
     * @return int quantos lançamentos foram criados
     */
    public function commit(DocumentUpload $documento, array $indicesAceitos, string $userId): int
    {
        if (! $documento->isAwaitingReview()) {
            throw new RuntimeException('Este documento não está aguardando revisão.');
        }

        $itens = $documento->extractedItems();
        $aceitos = array_values(array_intersect_key($itens, array_flip($indicesAceitos)));

        if ($aceitos === []) {
            throw new RuntimeException('Nenhum item foi selecionado para importar.');
        }

        return DB::transaction(function () use ($documento, $aceitos, $userId): int {
            $criados = match ($documento->document_type) {
                DocumentType::BankStatement => $this->bankStatement($documento, $aceitos, $userId),
                DocumentType::CreditCardInvoice => $this->creditCardInvoice($documento, $aceitos, $userId),
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

    /** Extrato: cada linha vira receita ou despesa. */
    private function bankStatement(DocumentUpload $documento, array $itens, string $userId): int
    {
        $criados = 0;

        foreach ($itens as $item) {
            $data = $this->parseDate($item['data'] ?? null);

            if ($data === null) {
                continue;
            }

            if (($item['tipo'] ?? null) === 'receita') {
                IncomeRecord::create([
                    'member_id' => $documento->member_id,
                    'category_id' => $this->incomeCategory($item['categoria_sugerida'] ?? null),
                    'description' => $item['descricao'] ?? 'Importado',
                    'amount' => Money::parse($item['valor'] ?? 0),
                    'received_date' => $data,
                    'source_document_id' => $documento->id,
                    'created_by_user_id' => $userId,
                ]);
            } else {
                ExpenseRecord::create([
                    'member_id' => $documento->member_id,
                    'description' => $item['descricao'] ?? 'Importado',
                    // Necessidade é julgamento humano, não da IA: o padrão
                    // é essencial e o usuário reclassifica no fluxo de caixa.
                    'necessity' => Necessity::Essential,
                    'category_id' => $this->expenseCategory($item['categoria_sugerida'] ?? null),
                    'amount' => Money::parse($item['valor'] ?? 0),
                    'expense_date' => $data,
                    'source_document_id' => $documento->id,
                    'created_by_user_id' => $userId,
                ]);
            }

            $criados++;
        }

        return $criados;
    }

    /** Fatura: cada linha vira despesa no cartão. */
    private function creditCardInvoice(DocumentUpload $documento, array $itens, string $userId): int
    {
        $criados = 0;

        foreach ($itens as $item) {
            $data = $this->parseDate($item['data'] ?? null);

            if ($data === null) {
                continue;
            }

            ExpenseRecord::create([
                'member_id' => $documento->member_id,
                'description' => $item['descricao'] ?? 'Importado',
                'necessity' => Necessity::Essential,
                'category_id' => $this->expenseCategory($item['categoria_sugerida'] ?? null),
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
