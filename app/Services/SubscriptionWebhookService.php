<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Processa um webhook da Asaas já autenticado (o token vem conferido pelo
 * controller, antes de chegar aqui). Entrega "at-least-once" — o mesmo
 * evento pode repetir; a tentativa de inserir em
 * subscription_webhook_events com `asaas_event_id` único é o que garante
 * processar uma vez só (mesma regra 4 do CLAUDE.md: índice único, não
 * "if existe").
 */
class SubscriptionWebhookService
{
    private const EVENTOS_PAGO = ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'];
    private const EVENTOS_ATRASADO = ['PAYMENT_OVERDUE'];
    private const EVENTOS_CANCELADO = ['SUBSCRIPTION_DELETED'];

    /** @param  array<string, mixed>  $payload */
    public function handle(array $payload): void
    {
        $eventId = $payload['id'] ?? null;
        $tipo = $payload['event'] ?? null;

        if ($eventId === null || $tipo === null) {
            return;
        }

        if (! $this->registrarSeNovo($eventId, $tipo)) {
            return; // já processado antes — reentrega da Asaas, ignora.
        }

        $asaasSubscriptionId = $payload['payment']['subscription'] ?? $payload['subscription']['id'] ?? null;

        if ($asaasSubscriptionId === null) {
            return;
        }

        $assinatura = Subscription::query()->where('asaas_subscription_id', $asaasSubscriptionId)->first();

        if ($assinatura === null) {
            return;
        }

        if (in_array($tipo, self::EVENTOS_PAGO, true)) {
            $assinatura->update([
                'status' => SubscriptionStatus::Active,
                'current_period_ends_at' => $this->proximoVencimento($payload),
            ]);
        } elseif (in_array($tipo, self::EVENTOS_ATRASADO, true)) {
            $assinatura->update(['status' => SubscriptionStatus::PastDue]);
        } elseif (in_array($tipo, self::EVENTOS_CANCELADO, true)) {
            $assinatura->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
        }
    }

    /** @return bool true se é a primeira vez que este evento é visto. */
    private function registrarSeNovo(string $eventId, string $tipo): bool
    {
        try {
            SubscriptionWebhookEvent::create([
                'asaas_event_id' => $eventId,
                'event_type' => $tipo,
                'processed_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function proximoVencimento(array $payload): ?string
    {
        return $payload['payment']['dueDate'] ?? null;
    }
}
