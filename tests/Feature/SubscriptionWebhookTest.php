<?php

namespace Tests\Feature;

use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionWebhookEvent;
use App\Models\User;
use App\Notifications\SubscriptionOverdue;
use App\Notifications\SubscriptionPaymentFailed;
use App\Services\AsaasClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Webhook da Asaas: entrega "at-least-once" (o mesmo evento pode chegar
 * mais de uma vez), autenticado por um token fixo no header
 * `asaas-access-token` — nunca pelo payload em si.
 */
class SubscriptionWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.asaas.webhook_token' => 'segredo-de-teste']);
    }

    public function test_token_errado_da_403(): void
    {
        $this->postJson(route('webhooks.asaas'), ['event' => 'PAYMENT_CONFIRMED'], ['asaas-access-token' => 'token-errado'])
            ->assertForbidden();
    }

    public function test_sem_token_da_403(): void
    {
        $this->postJson(route('webhooks.asaas'), ['event' => 'PAYMENT_CONFIRMED'])
            ->assertForbidden();
    }

    public function test_payment_confirmed_ativa_a_assinatura(): void
    {
        $assinatura = $this->criarAssinaturaPendente();

        $this->enviarWebhook('PAYMENT_CONFIRMED', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2026-11-01'],
        ])->assertNoContent();

        $assinatura->refresh();
        self::assertSame(SubscriptionStatus::Active, $assinatura->status);
        // dueDate do payload é o da cobrança paga: a próxima vence um mês depois.
        self::assertSame('2026-12-01', $assinatura->current_period_ends_at->toDateString());
        self::assertTrue($assinatura->isCurrent());
    }

    public function test_pagamento_atrasado_mantem_o_dia_do_mes_do_ciclo(): void
    {
        $assinatura = $this->criarAssinaturaPendente();

        // Venceu dia 10 e foi paga dia 14: o ciclo seguinte continua dia 10.
        $this->enviarWebhook('PAYMENT_RECEIVED', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2026-10-10'],
        ])->assertNoContent();

        self::assertSame('2026-11-10', $assinatura->fresh()->current_period_ends_at->toDateString());
    }

    public function test_evento_de_ciclo_antigo_nao_puxa_a_data_pra_tras(): void
    {
        $assinatura = $this->criarAssinaturaAtiva();
        $depois = $assinatura->current_period_ends_at->toDateString();

        $this->enviarWebhook('PAYMENT_RECEIVED', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2020-01-10'],
        ])->assertNoContent();

        self::assertSame($depois, $assinatura->fresh()->current_period_ends_at->toDateString());
    }

    public function test_payment_overdue_marca_em_atraso_e_avisa_por_email(): void
    {
        Notification::fake();
        $assinatura = $this->criarAssinaturaAtiva();
        $assinatura->update(['current_period_ends_at' => '2026-10-10']);

        $this->enviarWebhook('PAYMENT_OVERDUE', $assinatura->asaas_subscription_id, [
            'payment' => [
                'subscription' => $assinatura->asaas_subscription_id,
                'dueDate' => '2026-10-10',
                'invoiceUrl' => 'https://sandbox.asaas.com/i/atrasada',
            ],
        ])->assertNoContent();

        $assinatura->refresh();
        self::assertSame(SubscriptionStatus::PastDue, $assinatura->status);
        self::assertSame('2026-10-10', $assinatura->current_period_ends_at->toDateString());
        Notification::assertSentTo($assinatura->user, SubscriptionOverdue::class, function (SubscriptionOverdue $n) {
            return $n->dueDateFormatted === '10/10/2026'
                && $n->accessUntilFormatted === '15/10/2026'
                && $n->invoiceUrl === 'https://sandbox.asaas.com/i/atrasada';
        });
    }

    public function test_atraso_que_perdeu_a_confirmacao_anterior_avanca_o_vencimento(): void
    {
        Notification::fake();
        $assinatura = $this->criarAssinaturaAtiva();
        $assinatura->update(['current_period_ends_at' => '2026-09-10']);

        // O webhook do pagamento de setembro se perdeu: o atraso de outubro
        // chega com vencimento mais novo que o registrado.
        $this->enviarWebhook('PAYMENT_OVERDUE', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2026-10-10'],
        ])->assertNoContent();

        self::assertSame('2026-10-10', $assinatura->fresh()->current_period_ends_at->toDateString());
        Notification::assertSentTo($assinatura->user, SubscriptionOverdue::class);
    }

    public function test_atraso_de_ciclo_antigo_e_ignorado(): void
    {
        Notification::fake();
        $assinatura = $this->criarAssinaturaAtiva();

        $this->enviarWebhook('PAYMENT_OVERDUE', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2020-01-10'],
        ])->assertNoContent();

        self::assertSame(SubscriptionStatus::Active, $assinatura->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_cartao_recusado_marca_em_atraso_e_avisa_por_email(): void
    {
        Notification::fake();
        $assinatura = $this->criarAssinaturaAtiva();
        $assinatura->update(['current_period_ends_at' => '2026-10-10']);

        $this->enviarWebhook('PAYMENT_CREDIT_CARD_CAPTURE_REFUSED', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2026-10-10'],
        ])->assertNoContent();

        self::assertSame(SubscriptionStatus::PastDue, $assinatura->fresh()->status);
        Notification::assertSentTo($assinatura->user, SubscriptionPaymentFailed::class);
    }

    public function test_cobranca_removida_e_ignorada(): void
    {
        Notification::fake();
        $assinatura = $this->criarAssinaturaAtiva();

        $this->enviarWebhook('PAYMENT_DELETED', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2026-10-10'],
        ])->assertNoContent();

        self::assertSame(SubscriptionStatus::Active, $assinatura->fresh()->status);
        Notification::assertNothingSent();
    }

    /** @return array<string, array{0: string}> */
    public static function eventosDeEstornoOuChargeback(): array
    {
        return [
            'estorno' => ['PAYMENT_REFUNDED'],
            'chargeback' => ['PAYMENT_CHARGEBACK_REQUESTED'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('eventosDeEstornoOuChargeback')]
    public function test_estorno_ou_chargeback_encerra_o_acesso_e_cancela_na_asaas(string $evento): void
    {
        $assinatura = $this->criarAssinaturaAtiva();

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('cancelSubscription')->once()->with($assinatura->asaas_subscription_id);
        $this->app->instance(AsaasClient::class, $asaas);

        $this->enviarWebhook($evento, $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id],
        ])->assertNoContent();

        $assinatura->refresh();
        self::assertSame(SubscriptionStatus::Cancelled, $assinatura->status);
        self::assertNotNull($assinatura->cancelled_at);
        self::assertFalse($assinatura->isCurrent());
    }

    public function test_falha_ao_cancelar_na_asaas_nao_impede_de_revogar_o_acesso(): void
    {
        $assinatura = $this->criarAssinaturaAtiva();

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('cancelSubscription')->once()->andThrow(new \RuntimeException('Asaas fora do ar'));
        $this->app->instance(AsaasClient::class, $asaas);

        $this->enviarWebhook('PAYMENT_CHARGEBACK_REQUESTED', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id],
        ])->assertNoContent();

        self::assertSame(SubscriptionStatus::Cancelled, $assinatura->fresh()->status);
    }

    public function test_assinatura_cancelada_nao_volta_por_pagamento_nem_atraso_posterior(): void
    {
        Notification::fake();
        $assinatura = $this->criarAssinaturaAtiva();
        $assinatura->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

        $this->enviarWebhook('PAYMENT_CONFIRMED', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2026-10-10'],
        ])->assertNoContent();
        $this->enviarWebhook('PAYMENT_OVERDUE', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2026-10-10'],
        ])->assertNoContent();

        self::assertSame(SubscriptionStatus::Cancelled, $assinatura->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_subscription_deleted_cancela(): void
    {
        $assinatura = $this->criarAssinaturaAtiva();

        $this->enviarWebhook('SUBSCRIPTION_DELETED', $assinatura->asaas_subscription_id, [
            'subscription' => ['id' => $assinatura->asaas_subscription_id],
        ])->assertNoContent();

        $assinatura->refresh();
        self::assertSame(SubscriptionStatus::Cancelled, $assinatura->status);
        self::assertNotNull($assinatura->cancelled_at);
        self::assertFalse($assinatura->isCurrent());
    }

    public function test_mesmo_evento_reentregue_so_processa_uma_vez(): void
    {
        $assinatura = $this->criarAssinaturaPendente();

        $payload = [
            'id' => 'evt_repetido',
            'event' => 'PAYMENT_CONFIRMED',
            'payment' => ['subscription' => $assinatura->asaas_subscription_id, 'dueDate' => '2026-11-01'],
        ];

        $this->postJson(route('webhooks.asaas'), $payload, ['asaas-access-token' => 'segredo-de-teste'])->assertNoContent();
        $this->postJson(route('webhooks.asaas'), $payload, ['asaas-access-token' => 'segredo-de-teste'])->assertNoContent();

        self::assertSame(1, SubscriptionWebhookEvent::query()->where('asaas_event_id', 'evt_repetido')->count());
    }

    public function test_evento_de_assinatura_desconhecida_nao_quebra(): void
    {
        $this->enviarWebhook('PAYMENT_CONFIRMED', 'sub_nao_existe', [
            'payment' => ['subscription' => 'sub_nao_existe', 'dueDate' => '2026-11-01'],
        ])->assertNoContent();
    }

    private function criarAssinaturaPendente(): Subscription
    {
        return Subscription::create([
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::PastDue,
            'asaas_subscription_id' => 'sub_'.Str::random(10),
            'started_at' => now(),
        ]);
    }

    private function criarAssinaturaAtiva(): Subscription
    {
        return Subscription::create([
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(),
            'asaas_subscription_id' => 'sub_'.Str::random(10),
            'started_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function enviarWebhook(string $evento, string $asaasSubscriptionId, array $extra = [])
    {
        return $this->postJson(route('webhooks.asaas'), array_merge([
            'id' => 'evt_'.Str::random(10),
            'event' => $evento,
        ], $extra), ['asaas-access-token' => 'segredo-de-teste']);
    }
}
