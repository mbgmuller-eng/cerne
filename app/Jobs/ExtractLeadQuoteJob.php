<?php

namespace App\Jobs;

use App\Models\LeadAttachment;
use App\Services\Extraction\LeadQuoteExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lê a simulação em PDF de um lead em segundo plano: a leitura leva dezenas de segundos, mais do que a requisição da
 * hospedagem compartilhada aguenta (mesmo motivo de ProcessDocumentJob). O anexo de lead não tem escopo de perfil,
 * então não há contexto a montar aqui.
 */
class ExtractLeadQuoteJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public string $attachmentId) {}

    public function tries(): int
    {
        return config('cerne.ai.job_tries');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(LeadQuoteExtractionService $extractor): void
    {
        $anexo = LeadAttachment::query()->find($this->attachmentId);

        if ($anexo !== null) {
            $extractor->extract($anexo);
        }
    }

    public function failed(\Throwable $e): void
    {
        $anexo = LeadAttachment::query()->find($this->attachmentId);

        if ($anexo !== null) {
            app(LeadQuoteExtractionService::class)->fail($anexo, 'Falhou após várias tentativas: '.$e->getMessage());
        }
    }
}
