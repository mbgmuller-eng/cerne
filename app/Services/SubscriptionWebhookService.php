<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use App\Models\SubscriptionWebhookEvent;
use App\Notifications\PixAutomaticAuthorizationFailed;
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
    private const PREFIXO_PIX_AUTOMATICO = 'PIX_AUTOMATIC_RECURRING_';

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

        if (str_starts_with($tipo, 'INVOICE_')) {
            $this->tratarNotaFiscal($tipo, $payload);

            return;
        }

        if (str_starts_with($tipo, self::PREFIXO_PIX_AUTOMATICO)) {
            $this->tratarPixAutomatico($tipo, $payload);

            return;
        }

        $assinatura = $this->localizarAssinatura($payload);

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
            $this->marcarCobrancaPaga($payload);
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
     * Assinatura da Asaas (cartão e Pix comum) vem em `payment.subscription` ou
     * `subscription.id`. A cobrança mensal do Pix Automático é criada pelo
     * Cerne, sem assinatura na Asaas, e volta pela `externalReference`
     * (ver PixAutomaticBillingService::externalReference()).
     *
     * @param  array<string, mixed>  $payload
     */
    private function localizarAssinatura(array $payload): ?Subscription
    {
        $asaasSubscriptionId = $payload['payment']['subscription'] ?? $payload['subscription']['id'] ?? null;

        if ($asaasSubscriptionId !== null) {
            return Subscription::query()->where('asaas_subscription_id', $asaasSubscriptionId)->first();
        }

        $referencia = $payload['payment']['externalReference'] ?? null;

        if (is_string($referencia) && preg_match('/^cerne:([0-9a-f-]{36}):\d{4}-\d{2}$/', $referencia, $partes) === 1) {
            return Subscription::query()->find($partes[1]);
        }

        return null;
    }

    /** @param  array<string, mixed>  $payload */
    private function marcarCobrancaPaga(array $payload): void
    {
        $paymentId = $payload['payment']['id'] ?? null;

        if ($paymentId === null) {
            return;
        }

        SubscriptionCharge::query()->where('asaas_payment_id', $paymentId)->update([
            'paid_at' => now(),
            'retry_due_date' => null,
        ]);
    }

    /**
     * Notas fiscais (NFS-e) da Asaas. Nada muda na assinatura: só deixa rastro. Nota
     * recusada pela prefeitura (INVOICE_ERROR) é erro de verdade, o contador precisa
     * saber, então vai para o log como erro.
     *
     * @param  array<string, mixed>  $payload
     */
    private function tratarNotaFiscal(string $tipo, array $payload): void
    {
        $nota = $payload['invoice'] ?? [];
        $contexto = [
            'evento' => $tipo,
            'invoice_id' => $nota['id'] ?? null,
            'payment_id' => $nota['payment'] ?? null,
            'status' => $nota['status'] ?? null,
            'descricao' => $nota['statusDescription'] ?? null,
        ];

        if ($tipo === 'INVOICE_ERROR' || $tipo === 'INVOICE_CANCELLATION_DENIED') {
            Log::error('Asaas: problema na nota fiscal', $contexto);

            return;
        }

        Log::info('Asaas: evento de nota fiscal', $contexto);
    }

    /**
     * Eventos do Pix Automático: ciclo de vida da autorização e das instruções
     * de débito. As cobranças em si (recebida, vencida) chegam pelos eventos
     * PAYMENT_* de sempre, tratados acima.
     *
     * @param  array<string, mixed>  $payload
     */
    private function tratarPixAutomatico(string $tipo, array $payload): void
    {
        $sufixo = substr($tipo, strlen(self::PREFIXO_PIX_AUTOMATICO));

        if (str_starts_with($sufixo, 'AUTHORIZATION_')) {
            $this->tratarAutorizacao(substr($sufixo, strlen('AUTHORIZATION_')), $payload);

            return;
        }

        if (str_starts_with($sufixo, 'PAYMENT_INSTRUCTION_')) {
            $this->tratarInstrucao(substr($sufixo, strlen('PAYMENT_INSTRUCTION_')), $payload);

            return;
        }

        // ELIGIBILITY_UPDATED e afins: só registro. Perder a elegibilidade
        // significa que novas autorizações vão falhar, e isso aparece no log.
        Log::info('Asaas: evento de Pix Automático sem tratamento', ['evento' => $tipo, 'elegibilidade' => $payload['eligibility'] ?? null]);
    }

    /** @param  array<string, mixed>  $payload */
    private function tratarAutorizacao(string $evento, array $payload): void
    {
        $authorizationId = $payload['authorization']['id'] ?? null;
        $assinatura = $authorizationId === null
            ? null
            : Subscription::query()->where('asaas_pix_authorization_id', $authorizationId)->first();

        if ($assinatura === null) {
            return;
        }

        if ($evento === 'ACTIVATED') {
            $assinatura->update(['pix_authorization_status' => 'ACTIVE']);

            // O primeiro mês foi pago junto com a autorização (QR imediato).
            // Quem paga durante o teste não perde os dias que sobravam: o
            // próximo vencimento conta a partir do fim do teste.
            if ($assinatura->status !== SubscriptionStatus::Cancelled) {
                $base = $this->maisRecente($assinatura->current_period_ends_at, Carbon::today());

                $assinatura->update([
                    'status' => SubscriptionStatus::Active,
                    'current_period_ends_at' => $base->copy()->addMonthNoOverflow(),
                ]);
            }

            return;
        }

        $status = match ($evento) {
            'CANCELLED' => 'CANCELLED',
            'EXPIRED' => 'EXPIRED',
            'REFUSED' => 'REFUSED',
            default => null,
        };

        if ($status === null) {
            return; // CREATED: nada a fazer, o status já nasceu CREATED aqui.
        }

        $jaCancelada = $assinatura->status === SubscriptionStatus::Cancelled;
        $assinatura->update(['pix_authorization_status' => $status]);

        // Assinatura que o próprio cliente cancelou aqui não precisa de aviso.
        if (! $jaCancelada) {
            $assinatura->user->notify(new PixAutomaticAuthorizationFailed($assinatura->bundle->label(), $status === 'REFUSED'));
        }
    }

    /**
     * Instrução recusada: agenda a retentativa (2, 4 ou 6 dias após o
     * vencimento, no máximo 3) para o job pedir à Asaas — ver
     * PixAutomaticBillingService::requestPendingRetries(). Esgotadas as
     * tentativas, a Asaas deixa a cobrança vencida e o fluxo normal de atraso
     * (PAYMENT_OVERDUE) assume.
     *
     * @param  array<string, mixed>  $payload
     */
    private function tratarInstrucao(string $evento, array $payload): void
    {
        $instrucao = $payload['paymentInstruction'] ?? [];
        $instructionId = $instrucao['id'] ?? null;
        $paymentId = $instrucao['paymentId'] ?? $instrucao['payment']['id'] ?? null;

        if ($instructionId === null) {
            return;
        }

        $cobranca = SubscriptionCharge::query()
            ->when($paymentId !== null, fn ($q) => $q->where('asaas_payment_id', $paymentId), fn ($q) => $q->where('asaas_instruction_id', $instructionId))
            ->first();

        if ($cobranca === null) {
            return; // instrução do primeiro mês (QR imediato) ou de outra origem
        }

        // Sempre o id MAIS RECENTE: a retentativa de uma instrução recusada
        // usa o id da última recusa.
        $cobranca->update(['asaas_instruction_id' => $instructionId]);

        if ($evento !== 'REFUSED' || $cobranca->paid_at !== null) {
            return;
        }

        if ($cobranca->retry_attempts >= PixAutomaticBillingService::MAX_RETENTATIVAS) {
            return;
        }

        $tentativa = $cobranca->retry_attempts + 1;

        $cobranca->update([
            'retry_attempts' => $tentativa,
            'retry_due_date' => $cobranca->due_date->copy()->addDays($tentativa * PixAutomaticBillingService::INTERVALO_RETENTATIVA_DIAS),
            'retry_requested_at' => null,
        ]);
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
