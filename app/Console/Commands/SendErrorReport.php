<?php

namespace App\Console\Commands;

use App\Services\ErrorTracking\ErrorDigest;
use App\Services\ErrorTracking\ErrorRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Relatório diário de erros da plataforma por e-mail (rotina `relatorio-de-erros`, 08:10). Por padrão cobre ontem; `--date` escolhe outro
 * dia e `--force` reenvia mesmo que o dia já tenha saído. Também limpa o que é antigo e importa os erros que chegaram com o banco fora do ar.
 */
class SendErrorReport extends Command
{
    protected $signature = 'cerne:error-report
        {--date= : Dia do relatório (AAAA-MM-DD); padrão: ontem}
        {--force : Envia de novo mesmo que o dia já tenha saído}';

    protected $description = 'Envia por e-mail o relatório de erros da plataforma do dia anterior';

    public function handle(ErrorDigest $digest, ErrorRecorder $recorder): int
    {
        try {
            $dia = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date'))->startOfDay() : CarbonImmutable::yesterday();
        } catch (\Throwable) {
            $this->components->error('Data inválida: use AAAA-MM-DD.');

            return self::FAILURE;
        }

        $r = $digest->send($dia, (bool) $this->option('force'));
        $apagados = $recorder->prune();

        if ($r['status'] === 'sem destinatário') {
            $this->components->error('Nenhum destinatário: defina ERROR_REPORT_EMAILS ou cadastre um administrador da plataforma.');

            return self::FAILURE;
        }

        $this->components->info("Relatório de {$dia->format('d/m/Y')}: {$r['status']} ({$r['occurrences']} ocorrências) para ".implode(', ', $r['to']).'.');

        if ($apagados > 0) {
            $this->line("  Erros antigos removidos: {$apagados}");
        }

        return self::SUCCESS;
    }
}
