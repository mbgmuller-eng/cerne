<?php

use App\Services\FixedBillService;
use App\Services\InvestmentSnapshotService;
use App\Services\InvoiceService;
use App\Services\RecurringIncomeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Rotinas agendadas
|
| Na Hostinger tudo isto é disparado por um único cron a cada minuto
| (`php artisan schedule:run`) — ver DEPLOY.md. Por isso cada rotina
| precisa ser idempotente: rodar duas vezes não pode duplicar nada.
*/

/*
| Batimento do agendador.
|
| As rotinas de verdade rodam de madrugada, então não servem para
| responder "o cron está vivo?" no meio da tarde. Este carimbo por minuto
| serve: se ele estiver velho, o cron parou — e as contas fixas vão parar
| de nascer sem ninguém perceber até o cliente reclamar.
|
| Custo: uma escrita em cache por minuto.
*/
Schedule::call(function (): void {
    Cache::put('cerne:scheduler:heartbeat', now()->toIso8601String(), now()->addDays(2));
})->everyMinute()->name('batimento');

// Contas fixas: gera os vencimentos do mês e marca os atrasos.
Schedule::call(function (): void {
    $resultado = app(FixedBillService::class)->runDailyMaintenance();

    logger()->info('Contas fixas', $resultado);
})->dailyAt('03:10')->name('contas-fixas')->withoutOverlapping();

// Receitas recorrentes: gera os recebimentos do mês e marca os atrasos.
Schedule::call(function (): void {
    $resultado = app(RecurringIncomeService::class)->runDailyMaintenance();

    logger()->info('Receitas recorrentes', $resultado);
})->dailyAt('03:15')->name('receitas-recorrentes')->withoutOverlapping();

// Faturas de cartão: fecha o que passou do fechamento, marca as vencidas.
Schedule::call(function (): void {
    $resultado = app(InvoiceService::class)->runDailyMaintenance();

    logger()->info('Faturas', $resultado);
})->dailyAt('03:20')->name('faturas')->withoutOverlapping();

// Investimentos: foto mensal da carteira, no dia 1.
Schedule::call(function (): void {
    $criadas = app(InvestmentSnapshotService::class)->captureMonth();

    logger()->info('Snapshots de investimento', ['criadas' => $criadas]);
})->monthlyOn(1, '03:30')->name('snapshots')->withoutOverlapping();

// Documentos: os que ficaram "Na fila" porque a ANTHROPIC_API_KEY ainda
// não estava configurada no envio. Idempotente pelo próprio estado — um
// documento sai de Pending assim que o job o pega, então despachar de
// novo antes disso não duplica nada de errado.
Schedule::call(function (): void {
    if (blank(config('cerne.ai.api_key'))) {
        return;
    }

    \App\Models\DocumentUpload::withoutProfileScope()
        ->where('processing_status', \App\Enums\ProcessingStatus::Pending)
        ->pluck('id')
        ->each(fn (string $id) => \App\Jobs\ProcessDocumentJob::dispatch($id));
})->everyFiveMinutes()->name('documentos-pendentes')->withoutOverlapping();
