<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\User;
use App\Notifications\PixAutomaticAuthorizationFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Eventos do Pix Automático no webhook da Asaas: ciclo de vida da autorização,
 * recusa de instrução (agenda retentativa) e a volta da cobrança mensal pela
 * `externalReference`.
 */
class PixAutomaticWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.asaas.webhook_token' => 'segredo-de-teste']);
        Notification::fake();
    }

    private function assinatura(array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'billing_type' => PaymentMethod::PixAutomatic,
            'status' => SubscriptionStatus::Trialing,
            'current_period_ends_at' => Carbon::today()->addDays(3),
            'pix_authorization_status' => 'CREATED',
            'asaas_pix_authorization_id' => 'auth-123',
            'started_at' => now(),
        ]);
    }

    private function cobranca(Subscription $assinatura, array $extra = []): SubscriptionCharge
    {
        return SubscriptionCharge::create($extra + [
            'subscription_id' => $assinatura->id,
            'year' => 2026,
            'month' => 11,
            'due_date' => '2026-11-10',
            'value' => '29.90',
            'asaas_payment_id' => 'pay_1',
        ]);
    }

    /** @param  array<string, mixed>  $corpo */
    private function enviar(string $evento, array $corpo)
    {
        return $this->postJson(route('webhooks.asaas'), ['id' => 'evt_'.Str::random(12), 'event' => $evento] + $corpo, ['asaas-access-token' => 'segredo-de-teste']);
    }

    private function autorizacao(string $evento, string $id = 'auth-123', array $extra = [])
    {
        return $this->enviar("PIX_AUTOMATIC_RECURRING_AUTHORIZATION_{$evento}", ['authorization' => ['id' => $id] + $extra]);
    }

    private function instrucao(string $evento, string $instrucaoId, string $paymentId = 'pay_1')
    {
        return $this->enviar("PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_{$evento}", ['paymentInstruction' => ['id' => $instrucaoId, 'paymentId' => $paymentId]]);
    }

    public function test_ativacao_durante_o_teste_nao_perde_os_dias_que_sobravam(): void
    {
        $assinatura = $this->assinatura(['current_period_ends_at' => Carbon::today()->addDays(3)]);

        $this->autorizacao('ACTIVATED')->assertNoContent();

        $assinatura->refresh();
        self::assertSame('ACTIVE', $assinatura->pix_authorization_status);
        self::assertSame(SubscriptionStatus::Active, $assinatura->status);
        self::assertSame(Carbon::today()->addDays(3)->addMonthNoOverflow()->toDateString(), $assinatura->current_period_ends_at->toDateString());
    }

    public function test_ativacao_depois_do_fim_do_teste_conta_um_mes_a_partir_de_hoje(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::PastDue, 'current_period_ends_at' => Carbon::today()->subDays(2)]);

        $this->autorizacao('ACTIVATED')->assertNoContent();

        self::assertSame(Carbon::today()->addMonthNoOverflow()->toDateString(), $assinatura->fresh()->current_period_ends_at->toDateString());
        self::assertSame(SubscriptionStatus::Active, $assinatura->fresh()->status);
    }

    public function test_ativacao_nao_ressuscita_assinatura_cancelada(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::Cancelled]);

        $this->autorizacao('ACTIVATED')->assertNoContent();

        self::assertSame(SubscriptionStatus::Cancelled, $assinatura->fresh()->status);
    }

    public function test_autorizacao_recusada_avisa_a_pessoa(): void
    {
        $assinatura = $this->assinatura();

        $this->autorizacao('REFUSED')->assertNoContent();

        self::assertSame('REFUSED', $assinatura->fresh()->pix_authorization_status);
        Notification::assertSentTo($assinatura->user, PixAutomaticAuthorizationFailed::class, fn ($n) => $n->recusada === true);
    }

    public function test_cancelamento_pelo_banco_avisa_mas_nao_cancela_a_assinatura(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::Active, 'pix_authorization_status' => 'ACTIVE']);

        $this->autorizacao('CANCELLED', extra: ['cancellationReason' => 'REQUESTED_BY_PAYER'])->assertNoContent();

        $assinatura->refresh();
        self::assertSame('CANCELLED', $assinatura->pix_authorization_status);
        self::assertSame(SubscriptionStatus::Active, $assinatura->status);
        Notification::assertSentTo($assinatura->user, PixAutomaticAuthorizationFailed::class, fn ($n) => $n->recusada === false);
    }

    public function test_cancelamento_que_a_propria_pessoa_fez_aqui_nao_gera_aviso(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::Cancelled, 'pix_authorization_status' => 'ACTIVE']);

        $this->autorizacao('CANCELLED')->assertNoContent();

        self::assertSame('CANCELLED', $assinatura->fresh()->pix_authorization_status);
        Notification::assertNothingSent();
    }

    public function test_autorizacao_desconhecida_e_ignorada(): void
    {
        $this->assinatura();

        $this->autorizacao('ACTIVATED', 'auth-de-outra-origem')->assertNoContent();

        self::assertSame('CREATED', Subscription::query()->sole()->pix_authorization_status);
    }

    public function test_pagamento_da_cobranca_mensal_volta_pela_external_reference(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::PastDue, 'pix_authorization_status' => 'ACTIVE', 'current_period_ends_at' => '2026-11-10']);
        $cobranca = $this->cobranca($assinatura);

        $this->enviar('PAYMENT_RECEIVED', ['payment' => [
            'id' => 'pay_1',
            'externalReference' => 'cerne:'.$assinatura->id.':2026-11',
            'dueDate' => '2026-11-10',
        ]])->assertNoContent();

        $assinatura->refresh();
        self::assertSame(SubscriptionStatus::Active, $assinatura->status);
        self::assertSame('2026-12-10', $assinatura->current_period_ends_at->toDateString());
        self::assertNotNull($cobranca->fresh()->paid_at);
        self::assertNull($cobranca->fresh()->retry_due_date);
    }

    public function test_referencia_externa_malformada_nao_acha_assinatura(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::PastDue]);

        $this->enviar('PAYMENT_RECEIVED', ['payment' => ['id' => 'pay_x', 'externalReference' => 'qualquer-coisa', 'dueDate' => '2026-11-10']])->assertNoContent();

        self::assertSame(SubscriptionStatus::PastDue, $assinatura->fresh()->status);
    }

    public function test_cobranca_vencida_usa_o_fluxo_normal_de_atraso(): void
    {
        $assinatura = $this->assinatura(['status' => SubscriptionStatus::Active, 'pix_authorization_status' => 'ACTIVE', 'current_period_ends_at' => '2026-11-10']);
        $this->cobranca($assinatura);

        $this->enviar('PAYMENT_OVERDUE', ['payment' => [
            'id' => 'pay_1',
            'externalReference' => 'cerne:'.$assinatura->id.':2026-11',
            'dueDate' => '2026-11-10',
            'invoiceUrl' => 'https://sandbox.asaas.com/i/abc',
        ]])->assertNoContent();

        self::assertSame(SubscriptionStatus::PastDue, $assinatura->fresh()->status);
    }

    public function test_instrucao_recusada_agenda_retentativas_nos_dias_2_4_e_6_e_para_na_terceira(): void
    {
        $assinatura = $this->assinatura(['pix_authorization_status' => 'ACTIVE', 'status' => SubscriptionStatus::Active]);
        $cobranca = $this->cobranca($assinatura);

        foreach ([['ins-1', '2026-11-12', 1], ['ins-2', '2026-11-14', 2], ['ins-3', '2026-11-16', 3]] as [$instrucao, $data, $tentativa]) {
            $this->instrucao('REFUSED', $instrucao)->assertNoContent();

            $cobranca->refresh();
            self::assertSame($tentativa, $cobranca->retry_attempts);
            self::assertSame($data, $cobranca->retry_due_date->toDateString());
            self::assertSame($instrucao, $cobranca->asaas_instruction_id, 'sempre o id da recusa mais recente');
            self::assertNull($cobranca->retry_requested_at);
            $cobranca->update(['retry_requested_at' => now()]); // o job já pediu
        }

        $this->instrucao('REFUSED', 'ins-4')->assertNoContent();

        self::assertSame(3, $cobranca->fresh()->retry_attempts, 'a Asaas só aceita 3 retentativas');
    }

    public function test_recusa_depois_de_paga_nao_agenda_retentativa(): void
    {
        $assinatura = $this->assinatura(['pix_authorization_status' => 'ACTIVE']);
        $cobranca = $this->cobranca($assinatura, ['paid_at' => now()]);

        $this->instrucao('REFUSED', 'ins-1')->assertNoContent();

        self::assertSame(0, $cobranca->fresh()->retry_attempts);
        self::assertNull($cobranca->fresh()->retry_due_date);
    }

    public function test_instrucao_agendada_guarda_o_id_para_uma_recusa_futura(): void
    {
        $assinatura = $this->assinatura(['pix_authorization_status' => 'ACTIVE']);
        $cobranca = $this->cobranca($assinatura);

        $this->instrucao('SCHEDULED', 'ins-9')->assertNoContent();

        self::assertSame('ins-9', $cobranca->fresh()->asaas_instruction_id);
        self::assertSame(0, $cobranca->fresh()->retry_attempts);
    }

    public function test_instrucao_do_primeiro_mes_sem_cobranca_nossa_e_ignorada(): void
    {
        $this->assinatura();

        $this->instrucao('REFUSED', 'ins-1', 'pay_do_qr_inicial')->assertNoContent();

        self::assertSame(0, SubscriptionCharge::query()->count());
    }

    public function test_evento_repetido_agenda_uma_retentativa_so(): void
    {
        $assinatura = $this->assinatura(['pix_authorization_status' => 'ACTIVE']);
        $cobranca = $this->cobranca($assinatura);
        $corpo = ['id' => 'evt_repetido', 'event' => 'PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_REFUSED', 'paymentInstruction' => ['id' => 'ins-1', 'paymentId' => 'pay_1']];

        $this->postJson(route('webhooks.asaas'), $corpo, ['asaas-access-token' => 'segredo-de-teste'])->assertNoContent();
        $this->postJson(route('webhooks.asaas'), $corpo, ['asaas-access-token' => 'segredo-de-teste'])->assertNoContent();

        self::assertSame(1, $cobranca->fresh()->retry_attempts);
    }

    public function test_evento_de_elegibilidade_nao_quebra(): void
    {
        $this->enviar('PIX_AUTOMATIC_RECURRING_ELIGIBILITY_UPDATED', ['eligibility' => ['status' => 'INELIGIBLE', 'ineligibleReasons' => ['X']]])->assertNoContent();
    }
}
