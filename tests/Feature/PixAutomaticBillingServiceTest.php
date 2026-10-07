<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Notifications\SubscriptionAccessEnding;
use App\Services\AsaasClient;
use App\Services\PixAutomaticBillingService;
use App\Services\SubscriptionReminderService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Cobrança por Pix Automático (modo MANUAL): autorização, cobrança mensal
 * idempotente, retentativas pedidas por job e carência maior.
 */
class PixAutomaticBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function asaas(): MockInterface
    {
        $asaas = Mockery::mock(AsaasClient::class);
        $this->app->instance(AsaasClient::class, $asaas);

        return $asaas;
    }

    private function assinatura(array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'billing_type' => PaymentMethod::PixAutomatic,
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => Carbon::today()->addDays(5),
            'pix_authorization_status' => 'ACTIVE',
            'asaas_pix_authorization_id' => 'auth-123',
            'started_at' => now(),
        ]);
    }

    public function test_iniciar_autorizacao_guarda_o_id_e_devolve_o_qr(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::Trialing, 'pix_authorization_status' => null, 'asaas_pix_authorization_id' => null]);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->once()->andReturn('cus_1');
        $asaas->shouldReceive('createPixAuthorization')->once()->with('cus_1', $assinatura->id, '29.90')
            ->andReturn(['id' => 'auth-novo', 'status' => 'CREATED', 'payload' => '000201...', 'qrImage' => 'AAAA', 'expiresAt' => '2026-10-07 15:00:00']);

        $qr = app(PixAutomaticBillingService::class)->startAuthorization($assinatura);

        self::assertSame('000201...', $qr['payload']);
        $assinatura->refresh();
        self::assertSame('auth-novo', $assinatura->asaas_pix_authorization_id);
        self::assertSame('CREATED', $assinatura->pix_authorization_status);
    }

    public function test_refazer_o_qr_cancela_a_autorizacao_anterior_nao_concluida(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::Trialing, 'pix_authorization_status' => 'CREATED', 'asaas_pix_authorization_id' => 'auth-velho']);

        $asaas = $this->asaas();
        $asaas->shouldReceive('cancelPixAuthorization')->once()->with('auth-velho');
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAuthorization')->once()->andReturn(['id' => 'auth-novo', 'status' => 'CREATED', 'payload' => 'x', 'qrImage' => null, 'expiresAt' => null]);

        app(PixAutomaticBillingService::class)->startAuthorization($assinatura);

        self::assertSame('auth-novo', $assinatura->fresh()->asaas_pix_authorization_id);
    }

    public function test_autorizacao_ja_ativa_ou_outra_forma_de_pagamento_nao_inicia(): void
    {
        $this->asaas();
        $service = app(PixAutomaticBillingService::class);

        try {
            $service->startAuthorization($this->assinatura());
            self::fail('Era esperado DomainException para autorização já ativa.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $service->startAuthorization($this->assinatura(['billing_type' => PaymentMethod::Pix]));
    }

    public function test_cria_a_cobranca_do_ciclo_com_o_valor_do_dia_e_a_referencia_do_webhook(): void
    {
        $assinatura = $this->assinatura();

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAutomaticCharge')->once()
            ->with('cus_1', 'auth-123', '29.90', $assinatura->current_period_ends_at->toDateString(), 'cerne:'.$assinatura->id.':'.$assinatura->current_period_ends_at->format('Y-m'))
            ->andReturn('pay_1');

        self::assertSame(1, app(PixAutomaticBillingService::class)->createUpcomingCharges());

        $cobranca = SubscriptionCharge::query()->sole();
        self::assertSame('pay_1', $cobranca->asaas_payment_id);
        self::assertSame('29.90', $cobranca->value);
    }

    public function test_valor_do_profissional_vem_da_faixa_de_clientes(): void
    {
        $assinatura = $this->assinatura(['kind' => SubscriptionKind::Professional, 'client_cap' => 20]);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAutomaticCharge')->once()->withArgs(fn ($c, $a, $valor) => $valor === '149.90')->andReturn('pay_1');

        app(PixAutomaticBillingService::class)->createUpcomingCharges();

        self::assertSame('149.90', $assinatura->monthlyPrice());
    }

    public function test_rodar_duas_vezes_cria_uma_cobranca_so(): void
    {
        $this->assinatura();

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAutomaticCharge')->once()->andReturn('pay_1');

        $service = app(PixAutomaticBillingService::class);
        $service->createUpcomingCharges();
        $service->createUpcomingCharges();

        self::assertSame(1, SubscriptionCharge::query()->count());
    }

    public function test_so_cobra_quem_tem_debito_ativo_e_vence_dentro_da_janela(): void
    {
        $this->assinatura(['current_period_ends_at' => Carbon::today()->addDays(12)]);          // longe demais
        $this->assinatura(['pix_authorization_status' => 'CREATED']);                           // não autorizou
        $this->assinatura(['billing_type' => PaymentMethod::Pix]);                              // Pix comum
        $this->assinatura(['status' => SubscriptionStatus::Cancelled]);                         // cancelada
        $this->assinatura(['status' => SubscriptionStatus::PastDue]);                           // já tem cobrança vencida

        $asaas = $this->asaas();
        $asaas->shouldReceive('createPixAutomaticCharge')->never();

        self::assertSame(0, app(PixAutomaticBillingService::class)->createUpcomingCharges());
        self::assertSame(0, SubscriptionCharge::query()->count());
    }

    public function test_falha_da_asaas_libera_o_ciclo_para_a_proxima_execucao(): void
    {
        $this->assinatura();

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAutomaticCharge')->once()->andThrow(new RuntimeException('Asaas fora do ar'));
        $asaas->shouldReceive('createPixAutomaticCharge')->once()->andReturn('pay_1');

        $service = app(PixAutomaticBillingService::class);

        self::assertSame(0, $service->createUpcomingCharges());
        self::assertNull(SubscriptionCharge::query()->sole()->claimed_at);

        self::assertSame(1, $service->createUpcomingCharges());
        self::assertSame('pay_1', SubscriptionCharge::query()->sole()->asaas_payment_id);
    }

    public function test_reserva_recente_de_outra_execucao_bloqueia_e_reserva_velha_e_retomada(): void
    {
        $assinatura = $this->assinatura();
        $reserva = SubscriptionCharge::create([
            'subscription_id' => $assinatura->id,
            'year' => $assinatura->current_period_ends_at->year,
            'month' => $assinatura->current_period_ends_at->month,
            'due_date' => $assinatura->current_period_ends_at->toDateString(),
            'value' => '29.90',
            'claimed_at' => now(),
        ]);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAutomaticCharge')->once()->andReturn('pay_1');

        $service = app(PixAutomaticBillingService::class);
        self::assertSame(0, $service->createUpcomingCharges(), 'outra execução está cuidando deste ciclo');

        $reserva->update(['claimed_at' => now()->subMinutes(30)]);
        self::assertSame(1, $service->createUpcomingCharges());
    }

    public function test_retentativa_pendente_e_pedida_uma_vez_so(): void
    {
        $assinatura = $this->assinatura();
        $cobranca = $this->cobranca($assinatura, ['retry_attempts' => 1, 'retry_due_date' => Carbon::today()->addDay(), 'asaas_instruction_id' => 'ins-1']);

        $asaas = $this->asaas();
        $asaas->shouldReceive('retryPixInstruction')->once()->with('ins-1', Carbon::today()->addDay()->toDateString());

        $service = app(PixAutomaticBillingService::class);
        self::assertSame(1, $service->requestPendingRetries());
        self::assertSame(0, $service->requestPendingRetries());
        self::assertNotNull($cobranca->fresh()->retry_requested_at);
    }

    public function test_retentativa_com_data_vencida_e_descartada_e_falha_da_asaas_continua_pendente(): void
    {
        $assinatura = $this->assinatura();
        $velha = $this->cobranca($assinatura, ['year' => 2026, 'month' => 1, 'retry_attempts' => 1, 'retry_due_date' => Carbon::today(), 'asaas_instruction_id' => 'ins-velha']);
        $falha = $this->cobranca($assinatura, ['year' => 2026, 'month' => 2, 'retry_attempts' => 1, 'retry_due_date' => Carbon::today()->addDays(2), 'asaas_instruction_id' => 'ins-falha']);

        $asaas = $this->asaas();
        $asaas->shouldReceive('retryPixInstruction')->once()->with('ins-falha', Mockery::any())->andThrow(new RuntimeException('400'));

        self::assertSame(0, app(PixAutomaticBillingService::class)->requestPendingRetries());

        self::assertNull($velha->fresh()->retry_due_date);
        self::assertNotNull($falha->fresh()->retry_due_date);
        self::assertNull($falha->fresh()->retry_requested_at);
    }

    public function test_cobranca_ja_paga_nao_pede_retentativa(): void
    {
        $assinatura = $this->assinatura();
        $this->cobranca($assinatura, ['retry_attempts' => 1, 'retry_due_date' => Carbon::today()->addDay(), 'asaas_instruction_id' => 'ins-1', 'paid_at' => now()]);

        $asaas = $this->asaas();
        $asaas->shouldReceive('retryPixInstruction')->never();

        self::assertSame(0, app(PixAutomaticBillingService::class)->requestPendingRetries());
    }

    public function test_carencia_do_pix_automatico_e_de_7_dias_e_a_dos_outros_continua_5(): void
    {
        $automatico = $this->assinatura(['status' => SubscriptionStatus::PastDue, 'current_period_ends_at' => Carbon::today()->subDays(6)]);
        $comum = $this->assinatura(['status' => SubscriptionStatus::PastDue, 'billing_type' => PaymentMethod::Pix, 'current_period_ends_at' => Carbon::today()->subDays(6)]);

        self::assertTrue($automatico->isCurrent(), 'ainda dentro dos 7 dias: a última retentativa pode dar certo');
        self::assertFalse($comum->isCurrent());
        self::assertSame(Carbon::today()->addDay()->toDateString(), $automatico->accessCutoffDate()->toDateString());
    }

    public function test_aviso_de_acesso_encerrando_respeita_a_carencia_de_cada_forma_de_pagamento(): void
    {
        Notification::fake();
        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn(null);

        $automatico = $this->assinatura(['status' => SubscriptionStatus::PastDue, 'current_period_ends_at' => Carbon::today()->subDays(6)]);
        $comum = $this->assinatura(['status' => SubscriptionStatus::PastDue, 'billing_type' => PaymentMethod::Pix, 'current_period_ends_at' => Carbon::today()->subDays(4), 'asaas_subscription_id' => 'sub_x']);
        $foraDoDia = $this->assinatura(['status' => SubscriptionStatus::PastDue, 'current_period_ends_at' => Carbon::today()->subDays(4)]);

        self::assertSame(2, app(SubscriptionReminderService::class)->notifyAccessEndingTomorrow($asaas));

        Notification::assertSentTo($automatico->user, SubscriptionAccessEnding::class);
        Notification::assertSentTo($comum->user, SubscriptionAccessEnding::class);
        Notification::assertNotSentTo($foraDoDia->user, SubscriptionAccessEnding::class);
    }

    public function test_cancelar_a_assinatura_cancela_a_autorizacao_na_asaas(): void
    {
        $assinatura = $this->assinatura();

        $asaas = $this->asaas();
        $asaas->shouldReceive('cancelPixAuthorization')->once()->with('auth-123');

        app(PixAutomaticBillingService::class)->cancelAuthorization($assinatura);

        self::assertSame('CANCELLED', $assinatura->fresh()->pix_authorization_status);
    }

    /** @param  array<string, mixed>  $extra */
    private function cobranca(Subscription $assinatura, array $extra = []): SubscriptionCharge
    {
        return SubscriptionCharge::create($extra + [
            'subscription_id' => $assinatura->id,
            'year' => 2026,
            'month' => 11,
            'due_date' => Carbon::today()->subDay()->toDateString(),
            'value' => '29.90',
            'asaas_payment_id' => 'pay_'.uniqid(),
        ]);
    }
}
