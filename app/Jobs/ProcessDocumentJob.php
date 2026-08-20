<?php

namespace App\Jobs;

use App\Models\DocumentUpload;
use App\Services\Extraction\DocumentExtractionService;
use App\Support\ProfileContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processa o PDF em segundo plano.
 *
 * Precisa ser job, não requisição: a extração leva dezenas de segundos e
 * o `max_execution_time` da hospedagem compartilhada derrubaria a
 * requisição no meio. Na Hostinger a fila roda por cron
 * (`queue:work --stop-when-empty`), então o job também precisa ser curto
 * o bastante para caber entre duas execuções.
 */
class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public string $documentId) {}

    public function tries(): int
    {
        return config('cerne.ai.job_tries');
    }

    /** Espera crescente entre tentativas: a API pode estar sobrecarregada. */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(DocumentExtractionService $extractor, ProfileContext $context): void
    {
        // A fila roda sem requisição HTTP, então não há middleware para
        // montar o contexto — o job precisa fazê-lo, senão o escopo global
        // não encontra o próprio documento.
        $documento = DocumentUpload::withoutProfileScope()->find($this->documentId);

        if ($documento === null) {
            return;
        }

        $context->set($documento->profile);

        $extractor->extract($documento);
    }

    public function failed(\Throwable $e): void
    {
        DocumentUpload::withoutProfileScope()
            ->where('id', $this->documentId)
            ->update([
                'processing_status' => \App\Enums\ProcessingStatus::Failed,
                'error_message' => 'Falhou após várias tentativas: '.$e->getMessage(),
                'processed_at' => now(),
            ]);
    }
}
