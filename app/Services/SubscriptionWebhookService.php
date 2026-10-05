<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionWebhookEvent;
use App\Notifications\SubscriptionOverdue;
use App\Notifications\SubscriptionPaymentFailed;
use Illuminate\Database\UniqueConstraintViolationException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Processa um webhook da Asaas já autenticado (o token vem conferido pelo
 * controller, antes de chegar aqui). Entrega "at-least-once" — o mesmo
 * evento pode repetir; a tentativa de inserir em
 * subscription_webhook_events com `asaas_event_id` único é o que garante
 * processar uma vez só (mesma regra 4 do CLAUDE.md: índice único, não
 * "if existe").
 *
 * PAYMENT_DELETED é ignorado de propósito: a Asaas apaga as cobranças
 * pendentes quando a assinatura é cancelada (por nós ou por ela), e o
 * cancelamento já chega por SUBSCRIPTION_DELETED.
 */
class SubscriptionWebhookService
{
    private const EVENTOS_PAGO = ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'];
    private const EVENTOS_ATRASADO = ['PAYMENT_OVERDUE'];
    private const EVENTOS_CARTAO_RECUSADO = ['PAYMENT_CREDIT_CARD_CAPTURE_REFUSED'];
    private const EVENTOS_REVERTIDO = ['PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK_REQUESTED'];
    private const EVENTOS_CANCELADO = ['SUBSCRIPTION_DELETED'];

    public function __construct(private readonly AsaasClient $asaas) {}

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

        if (in_array($tipo, self::EVENTOS_CANCELADO, true)) {
            $assinatura->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

            return;
        }

        // Assinatura já cancelada (por nós ou num upgrade de faixa) não
        // volta à vida por um pagamento ou atraso que chegou depois.
        if ($assinatura->status === SubscriptionStatus::Cancelled) {
            return;
        }

        if (in_array($tipo, self::EVENTOS_PAGO, true)) {
            $assinatura->update([
                'status' => SubscriptionStatus::Active,
                'current_period_ends_at' => $this->proximoVencimento($payload, $assinatura),
            ]);
        } elseif (in_array($tipo, self::EVENTOS_ATRASADO, true)) {
            if ($this->eDeCicloAntigo($assinatura, $payload)) {
                return;
            }

            $this->marcarEmAtraso($assinatura, $payload);
            $assinatura->user->notify(new SubscriptionOverdue(
                $assinatura->bundle->label(),
                $assinatura->current_period_ends_at->format('d/m/Y'),
                $assinatura->accessCutoffDate()->format('d/m/Y'),
                $payload['payment']['invoiceUrl'] ?? null,
            ));
        } elseif (in_array($tipo, self::EVENTOS_CARTAO_RECUSADO, true)) {
            if ($this->eDeCicloAntigo($assinatura, $payload)) {
                return;
            }

            $this->marcarEmAtraso($assinatura, $payload);
            $assinatura->user->notify(new SubscriptionPaymentFailed(
                $assinatura->bundle->label(),
                $assinatura->accessCutoffDate()->format('d/m/Y'),
                $payload['payment']['invoiceUrl'] ?? null,
            ));
        } elseif (in_array($tipo, self::EVENTOS_REVERTIDO, true)) {
            $this->encerrarPorEstornoOuChargeback($assinatura);
        }
    }

    /**
     * Falha/atraso de uma cobrança MAIS ANTIGA que o ciclo já registrado
     * (ex.: evento entregue fora de ordem, depois de um pagamento mais novo
     * já confirmado): marcar a assinatura em atraso por causa dele estaria
     * errado.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eDeCicloAntigo(Subscription $assinatura, array $payload): bool
    {
        $vencimento = $this->vencimentoDoPayload($payload);

        return $vencimento !== null
            && $assinatura->current_period_ends_at !== null
            && $vencimento->lt($assinatura->current_period_ends_at);
    }

    /**
     * A carência (Subscription::accessCutoffDate()) conta do vencimento da
     * cobrança que falhou, não de um pagamento antigo que o webhook possa
     * ter perdido — por isso avança o vencimento quando o payload traz um
     * mais novo.
     *
     * @param  array<string, mixed>  $payload
     */
    private function marcarEmAtraso(Subscription $assinatura, array $payload): void
    {
        $vencimento = $this->vencimentoDoPayload($payload);

        $assinatura->update([
            'status' => SubscriptionStatus::PastDue,
            'current_period_ends_at' => $this->maisRecente($assinatura->current_period_ends_at, $vencimento) ?? Carbon::today(),
        ]);
    }

    /**
     * Estorno ou chargeback: a pessoa recebeu o dinheiro de volta (ou o
     * contestou), então o acesso acaba na hora e a assinatura para de
     * gerar cobranças na Asaas — senão cobraria de novo o ciclo seguinte
     * de quem acabou de contestar. Falha ao cancelar lá não impede de
     * revogar aqui: o acesso é o que importa proteger.
     *
     * Limitação conhecida: estorno de uma cobrança ANTIGA também cancela
     * (o webhook não diz se era a do ciclo atual). Estorno é feito à mão
     * pelo painel da Asaas, então é raro e quem faz vê o efeito.
     */
    private function encerrarPorEstornoOuChargeback(Subscription $assinatura): void
    {
        if ($assinatura->asaas_subscription_id !== null) {
            try {
                $this->asaas->cancelSubscription($assinatura->asaas_subscription_id);
            } catch (\Throwable $e) {
                Log::warning('Asaas: não conseguiu cancelar a assinatura após estorno/chargeback', [
                    'subscription_id' => $assinatura->asaas_subscription_id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        $assinatura->update(['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);
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

    /**
     * O `dueDate` do payload é o da cobrança que ACABOU de ser paga; o
     * próximo ciclo vence um mês depois (a Asaas mantém o mesmo dia do
     * mês). Nunca recua: um evento atrasado de um ciclo antigo não pode
     * puxar a data pra trás.
     *
     * @param  array<string, mixed>  $payload
     */
    private function proximoVencimento(array $payload, Subscription $assinatura): ?CarbonInterface
    {
        $pago = $this->vencimentoDoPayload($payload);

        return $this->maisRecente($assinatura->current_period_ends_at, $pago?->copy()->addMonthNoOverflow());
    }

    /** @param  array<string, mixed>  $payload */
    private function vencimentoDoPayload(array $payload): ?CarbonInterface
    {
        $dueDate = $payload['payment']['dueDate'] ?? null;

        return $dueDate === null ? null : Carbon::parse($dueDate)->startOfDay();
    }

    private function maisRecente(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->gte($b) ? $a : $b;
    }
}
