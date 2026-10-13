<?php

namespace App\Console\Commands;

use App\Services\LeadService;
use Illuminate\Console\Command;

/**
 * Apaga os anexos de leads perdidos há mais tempo que o prazo de retenção (config cerne.lead_files.retention_months).
 * Roda todo dia pelo agendador; é seguro rodar de novo (o que já saiu não volta a aparecer).
 */
class PurgeLeadFiles extends Command
{
    protected $signature = 'cerne:purge-lead-files';

    protected $description = 'Apaga os anexos de leads perdidos há mais tempo que o prazo de retenção';

    public function handle(LeadService $leads): int
    {
        $apagados = $leads->purgeExpiredAttachments();

        $this->info($apagados === 0 ? 'Nenhum anexo vencido.' : "{$apagados} anexo(s) apagado(s).");

        return self::SUCCESS;
    }
}
