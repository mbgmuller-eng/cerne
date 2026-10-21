<?php

namespace App\Jobs;

use App\Mail\DataExportReadyMail;
use App\Models\DataExport;
use App\Services\Privacy\UserDataExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Monta o zip de "baixar meus dados" em segundo plano: em conta grande leva mais do que a requisição da hospedagem compartilhada aguenta
 * (mesmo motivo de ProcessDocumentJob) e avisa por e-mail quando está pronto.
 */
class ExportUserDataJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public string $exportId) {}

    public function handle(UserDataExporter $exporter): void
    {
        $pedido = DataExport::query()->with('user')->find($this->exportId);

        if ($pedido === null || $pedido->user === null || $pedido->status === DataExport::READY) {
            return;
        }

        $pronto = $exporter->export($pedido->user, $pedido);

        Mail::to($pedido->user->email)->send(new DataExportReadyMail($pronto));
    }

    public function failed(\Throwable $e): void
    {
        DataExport::query()->whereKey($this->exportId)->update(['status' => DataExport::FAILED]);
    }
}
