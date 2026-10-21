<?php

namespace App\Services\Privacy;

use App\Enums\ProcessingStatus;
use App\Models\DataExport;
use App\Models\DocumentUpload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Política de retenção (LGPD, art. 15 e 16): dado pessoal só fica enquanto serve a uma finalidade.
 *
 * Tudo aqui é idempotente (regra 4 do CLAUDE.md): o que já foi apagado não aparece na consulta de novo, então o cron repetido ou
 * concorrente não faz mal. O PDF original de extrato e fatura, que traz CPF, endereço e número de conta, é o dado mais sensível que o
 * Cerne guarda sem precisar: depois de lido e confirmado (ou descartado, ou falho) só serve para conferência, e a conferência tem prazo.
 */
class DataRetentionService
{
    public function __construct(private readonly AccountDeletionService $deletion) {}

    /** Exclui as contas cuja carência acabou. Conta que falha (ex.: cobrança não cancelada) fica para a próxima rodada. */
    public function deleteDueAccounts(): int
    {
        $apagadas = 0;

        User::query()->whereNotNull('deletion_due_at')->where('deletion_due_at', '<=', now())->orderBy('id')->each(function (User $usuario) use (&$apagadas): void {
            // Um administrador da plataforma nunca é apagado pela rotina, mesmo que o pedido tenha sido gravado por outro caminho.
            if ($usuario->isPlatformAdmin()) {
                return;
            }

            try {
                $this->deletion->delete($usuario, 'titular');
                $apagadas++;
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $apagadas;
    }

    /** @return array{exports: int, source_files: int} */
    public function purge(): array
    {
        return ['exports' => $this->purgeExports(), 'source_files' => $this->purgeSourceFiles()];
    }

    /** Cópia de dados vencida (7 dias) ou que nunca terminou (2 dias): o arquivo tem dado pessoal e não fica parado no servidor. */
    private function purgeExports(): int
    {
        $apagados = 0;

        DataExport::query()
            ->where(fn ($q) => $q->where('expires_at', '<', now())->orWhere(fn ($q) => $q->whereIn('status', [DataExport::PENDING, DataExport::FAILED])->where('created_at', '<', now()->subDays(2))))
            ->orderBy('id')
            ->each(function (DataExport $pedido) use (&$apagados): void {
                if ($pedido->path !== null) {
                    Storage::disk('local')->delete($pedido->path);
                }

                $pedido->delete();
                $apagados++;
            });

        return $apagados;
    }

    /** PDF original de importação: apaga o arquivo depois do prazo, mantém a linha (o histórico do que foi importado continua). */
    private function purgeSourceFiles(): int
    {
        $dias = (int) config('cerne.legal.source_pdf_days', 90);
        $limite = now()->subDays($dias);
        $apagados = 0;

        DocumentUpload::withoutGlobalScopes()
            ->where('storage_path', '!=', '')
            ->where(fn ($q) => $q->whereIn('processing_status', [ProcessingStatus::Committed->value, ProcessingStatus::Failed->value])->orWhereNotNull('dismissed_at'))
            ->where('updated_at', '<', $limite)
            ->orderBy('id')
            ->each(function (DocumentUpload $upload) use (&$apagados): void {
                $upload->deleteFile();
                // `storage_path` é NOT NULL: vazio quer dizer "arquivo já removido".
                $upload->forceFill(['storage_path' => ''])->saveQuietly();
                $apagados++;
            });

        return $apagados;
    }
}
