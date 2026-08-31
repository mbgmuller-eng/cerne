<?php

namespace App\Services\Extraction;

use App\Enums\ProcessingStatus;
use App\Models\DocumentUpload;
use App\Notifications\DocumentProcessed;
use Anthropic\Client;
use RuntimeException;

/**
 * Extração de dados de PDF pela API da Anthropic.
 *
 * O PDF vai como bloco `document` em base64, junto com um esquema de
 * saída estruturada — assim a resposta é JSON válido conforme o formato,
 * sem precisar garimpar JSON em texto livre.
 *
 * O resultado NÃO vira lançamento aqui. Fica em `extraction_summary`
 * aguardando revisão humana: dado financeiro extraído por IA entra depois
 * de alguém conferir, nunca antes.
 */
class DocumentExtractionService
{
    /** Limites da API para PDF em base64. */
    private const MAX_BYTES = 32 * 1024 * 1024;

    public function extract(DocumentUpload $documento): DocumentUpload
    {
        if (! $documento->document_type->isExtractable()) {
            return $this->fail($documento, 'Este tipo de documento não tem extração automática.');
        }

        $conteudo = $documento->contents();

        if (strlen($conteudo) > self::MAX_BYTES) {
            return $this->fail($documento, 'O arquivo excede o limite de 32 MB da extração.');
        }

        $documento->update(['processing_status' => ProcessingStatus::Processing]);

        try {
            $dados = $this->ask($documento, $conteudo);
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($documento, 'Falha ao processar: '.$e->getMessage());
        }

        $itens = $dados['itens'] ?? [];

        $documento->update([
            'processing_status' => ProcessingStatus::Completed,
            'institution_name' => $dados['instituicao'] ?? null,
            'reference_month' => $dados['competencia_mes'] ?? null,
            'reference_year' => $dados['competencia_ano'] ?? null,
            'records_extracted' => count($itens),
            'extraction_summary' => [
                'itens' => $itens,
                'observacoes' => $dados['observacoes'] ?? null,
                'extraido_em' => now()->toIso8601String(),
                'modelo' => config('cerne.ai.model'),
            ],
            'processed_at' => now(),
            'error_message' => null,
        ]);

        $documento->refresh();
        $this->notify($documento);

        return $documento;
    }

    /**
     * A chamada em si.
     *
     * @return array<string, mixed>
     */
    private function ask(DocumentUpload $documento, string $conteudo): array
    {
        $tipo = $documento->document_type;

        $resposta = $this->client()->messages->create(
            model: config('cerne.ai.model'),
            maxTokens: config('cerne.ai.max_tokens'),
            system: DocumentSchemas::promptFor($tipo),
            messages: [[
                'role' => 'user',
                'content' => [
                    // O documento vem ANTES do texto: é o que a API recomenda
                    // para que o modelo leia o anexo antes da instrução.
                    [
                        'type' => 'document',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => 'application/pdf',
                            'data' => base64_encode($conteudo),
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Extraia os dados deste documento seguindo o formato definido.',
                    ],
                ],
            ]],
            outputConfig: [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => DocumentSchemas::for($tipo),
                ],
                'effort' => config('cerne.ai.effort'),
            ],
        );

        // Uma recusa devolve HTTP 200 com stop_reason "refusal" e conteúdo
        // vazio — ler content[0] direto quebraria aqui.
        if ($resposta->stopReason === 'refusal') {
            throw new RuntimeException('O modelo recusou processar este documento.');
        }

        if ($resposta->stopReason === 'max_tokens') {
            throw new RuntimeException(
                'O documento é extenso demais para uma extração única. Divida o arquivo e tente de novo.'
            );
        }

        foreach ($resposta->content as $bloco) {
            if ($bloco->type === 'text') {
                $json = json_decode($bloco->text, true, flags: JSON_THROW_ON_ERROR);

                return is_array($json) ? $json : [];
            }
        }

        throw new RuntimeException('A resposta da API não trouxe conteúdo.');
    }

    private function client(): Client
    {
        $chave = config('cerne.ai.api_key');

        if (blank($chave)) {
            throw new RuntimeException(
                'ANTHROPIC_API_KEY não configurada — a importação por IA está indisponível.'
            );
        }

        return new Client(apiKey: $chave);
    }

    private function fail(DocumentUpload $documento, string $mensagem): DocumentUpload
    {
        $documento->update([
            'processing_status' => ProcessingStatus::Failed,
            'error_message' => $mensagem,
            'processed_at' => now(),
        ]);

        $documento->refresh();
        $this->notify($documento);

        return $documento;
    }

    private function notify(DocumentUpload $documento): void
    {
        $documento->uploadedBy?->notify(DocumentProcessed::forDocument($documento));
    }
}
