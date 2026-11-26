<?php

namespace App\Jobs;

use App\Models\FinancialProfile;
use App\Models\HealthExamReading;
use App\Services\HealthExamReadingService;
use App\Support\ProfileContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Lê o PDF de um laudo em segundo plano (mesmo motivo do ProcessDocumentJob: a leitura é longa e a fila roda por cron). */
class ReadExamReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(public string $readingId) {}

    public function tries(): int
    {
        return config('cerne.ai.job_tries');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(HealthExamReadingService $leitor, ProfileContext $contexto): void
    {
        $leitura = HealthExamReading::withoutGlobalScopes()->find($this->readingId);

        if ($leitura === null) {
            return;
        }

        $perfil = FinancialProfile::withCareProfiles()->find($leitura->profile_id);

        if ($perfil === null) {
            return;
        }

        $contexto->set($perfil);

        try {
            $leitor->extract($leitura);
        } finally {
            // O contexto é um singleton: num worker que não para a cada rodada ele vazaria para o próximo job.
            $contexto->clear();
        }
    }

    public function failed(\Throwable $e): void
    {
        $leitura = HealthExamReading::withoutGlobalScopes()->find($this->readingId);

        if ($leitura !== null && $leitura->isOpen()) {
            app(HealthExamReadingService::class)->fail($leitura, 'Não foi possível ler este laudo. Tente enviar de novo ou digite o resultado à mão.');
        }
    }
}
