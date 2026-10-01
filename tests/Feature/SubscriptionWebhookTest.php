<?php

namespace Tests\Feature;

use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionWebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        self::assertSame('2026-11-01', $assinatura->current_period_ends_at->toDateString());
        self::assertTrue($assinatura->isCurrent());
    }

    public function test_payment_overdue_marca_em_atraso(): void
    {
        $assinatura = $this->criarAssinaturaAtiva();

        $this->enviarWebhook('PAYMENT_OVERDUE', $assinatura->asaas_subscription_id, [
            'payment' => ['subscription' => $assinatura->asaas_subscription_id],
        ])->assertNoContent();

        self::assertSame(SubscriptionStatus::PastDue, $assinatura->fresh()->status);
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
