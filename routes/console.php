<?php

use App\Services\FixedBillService;
use App\Services\HealthAppointmentService;
use App\Services\ImportantDatesService;
use App\Services\InvestmentSnapshotService;
use App\Services\InvoiceService;
use App\Services\AsaasClient;
use App\Services\RecurringIncomeService;
use App\Services\PixAutomaticBillingService;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionReminderService;
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

// Contas fixas: gera os vencimentos do mês, marca os atrasos e avisa quem
// tem conta vencendo em breve.
Schedule::call(function (): void {
    $service = app(FixedBillService::class);
    $resultado = $service->runDailyMaintenance();
    $resultado['notificados'] = $service->notifyUpcomingDueDates();

    logger()->info('Contas fixas', $resultado);
})->dailyAt('03:10')->name('contas-fixas')->withoutOverlapping();

// Receitas recorrentes: gera os recebimentos do mês e marca os atrasos.
Schedule::call(function (): void {
    $resultado = app(RecurringIncomeService::class)->runDailyMaintenance();

    logger()->info('Receitas recorrentes', $resultado);
})->dailyAt('03:15')->name('receitas-recorrentes')->withoutOverlapping();

// Faturas de cartão: fecha o que passou do fechamento, marca as vencidas e
// avisa quem tem fatura vencendo em breve (nessa ordem: precisa fechar antes
// de decidir quem está "vencendo em breve").
Schedule::call(function (): void {
    $service = app(InvoiceService::class);
    $resultado = $service->runDailyMaintenance();
    $resultado['notificados'] = $service->notifyUpcomingDueDates();

    logger()->info('Faturas', $resultado);
})->dailyAt('03:20')->name('faturas')->withoutOverlapping();

// Investimentos: foto mensal da carteira, no dia 1.
Schedule::call(function (): void {
    $criadas = app(InvestmentSnapshotService::class)->captureMonth();

    logger()->info('Snapshots de investimento', ['criadas' => $criadas]);
})->monthlyOn(1, '03:30')->name('snapshots')->withoutOverlapping();

// Datas importantes: aniversário de cliente/cônjuge, renovação/vencimento
// de apólice, vencimento de investimento — avisa o(s) profissional(is)
// vinculado(s), nunca o cliente (ver ImportantDatesService).
Schedule::call(function (): void {
    $service = app(ImportantDatesService::class);
    $resultado = [
        'aniversarios' => $service->notifyUpcomingBirthdays(),
        'aniversarios_apolice' => $service->notifyUpcomingPolicyAnniversaries(),
        'vencimentos_apolice' => $service->notifyUpcomingPolicyExpiries(),
        'vencimentos_investimento' => $service->notifyUpcomingInvestmentMaturities(),
    ];

    logger()->info('Datas importantes', $resultado);
})->dailyAt('03:25')->name('datas-importantes')->withoutOverlapping();

// Agenda de saúde: avisa consulta/exame de amanhã — pros donos da conta,
// nunca pro consultor (ver HealthAppointmentService::recipientsFor()).
Schedule::call(function (): void {
    $notificados = app(HealthAppointmentService::class)->notifyUpcoming();

    logger()->info('Agenda de saúde', ['notificados' => $notificados]);
})->dailyAt('03:35')->name('agenda-saude')->withoutOverlapping();

// Cartão e Pix: cria a assinatura na Asaas perto do fim do teste grátis (no
// cadastro nada vai para a Asaas, que gera a cobrança no ato). Roda ANTES do
// lembrete de Pix (03:40) para o aviso já levar o link da fatura.
Schedule::call(function (): void {
    $criadas = app(SubscriptionBillingService::class)->createDueSubscriptions();

    logger()->info('Assinaturas criadas na Asaas (fim do teste)', ['criadas' => $criadas]);
})->dailyAt('03:30')->name('assinaturas-fim-do-teste')->withoutOverlapping();

// Assinatura por Pix: sem débito automático, avisa 3 dias antes do
// vencimento (fim do teste grátis ou qualquer ciclo seguinte — é o mesmo
// problema se repetindo todo mês).
Schedule::call(function (): void {
    $notificados = app(SubscriptionReminderService::class)->notifyUpcomingPixDueDates(app(AsaasClient::class));

    logger()->info('Lembrete de Pix', ['notificados' => $notificados]);
})->dailyAt('03:40')->name('lembrete-pix')->withoutOverlapping();

// Pix Automático: cria a cobrança do próximo vencimento de quem já autorizou o
// débito (a Asaas pede de 2 a 10 dias úteis de antecedência; o serviço cobra
// 7 dias antes). Idempotente pelo índice único de subscription_charges.
Schedule::call(function (): void {
    $criadas = app(PixAutomaticBillingService::class)->createUpcomingCharges();

    logger()->info('Cobranças do Pix Automático', ['criadas' => $criadas]);
})->dailyAt('03:50')->name('pix-automatico-cobrancas')->withoutOverlapping();

// Pix Automático: pede à Asaas as retentativas das instruções recusadas
// (dias 2, 4 e 6 após o vencimento). De manhã, porque a Asaas rejeita pedido
// feito no próprio dia da data pedida.
Schedule::call(function (): void {
    $pedidas = app(PixAutomaticBillingService::class)->requestPendingRetries();

    logger()->info('Retentativas do Pix Automático', ['pedidas' => $pedidas]);
})->dailyAt('03:55')->name('pix-automatico-retentativas')->withoutOverlapping();

// Pix Automático: lembra de ativar o débito automático 3 dias antes de o
// teste grátis acabar.
Schedule::call(function (): void {
    $avisados = app(SubscriptionReminderService::class)->notifyPixAutomaticActivation();

    logger()->info('Lembrete de ativação do Pix Automático', ['avisados' => $avisados]);
})->dailyAt('03:42')->name('lembrete-pix-automatico')->withoutOverlapping();

// Assinatura em atraso: avisa no último dia da carência que o acesso é
// cortado amanhã. Idempotente por índice único (subscription_notices).
Schedule::call(function (): void {
    $avisados = app(SubscriptionReminderService::class)->notifyAccessEndingTomorrow(app(AsaasClient::class));

    logger()->info('Aviso de acesso encerrando', ['avisados' => $avisados]);
})->dailyAt('03:45')->name('aviso-acesso-encerrando')->withoutOverlapping();

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
