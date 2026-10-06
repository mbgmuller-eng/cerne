<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionCharge;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cobrança por Pix Automático. A pessoa autoriza uma vez no banco (o QR do
 * primeiro mês é também o pedido de autorização) e o Cerne cria cada cobrança
 * mensal pela API da Asaas (modo MANUAL) — ver AsaasClient::createPixAuthorization()
 * para o porquê do modo.
 *
 * Tudo aqui que roda por agendamento é idempotente por estado, não por
 * `if (existe)`: o ciclo é reservado pelo índice único de subscription_charges
 * e as retentativas só saem uma vez (`retry_requested_at`) — o cron da
 * hospedagem compartilhada pode disparar repetido ou concorrente.
 */
class PixAutomaticBillingService
{
    /** A Asaas aceita criar a cobrança entre 2 e 10 dias úteis antes do vencimento; 7 corridos cabem sempre. */
    public const DIAS_DE_ANTECEDENCIA = 7;

    /** Tentativas extras permitidas pela Asaas (retryPolicy ALLOW_THREE_IN_SEVEN_DAYS). */
    public const MAX_RETENTATIVAS = 3;

    /** Dias entre o vencimento e cada retentativa: 2, 4 e 6 — a última cabe nos 7 dias da Asaas e na carência. */
    public const INTERVALO_RETENTATIVA_DIAS = 2;

    /** Tempo em que uma reserva de ciclo sem resposta da Asaas ainda bloqueia outra execução. */
    private const RESERVA_MINUTOS = 10;

    public function __construct(private readonly AsaasClient $asaas) {}

    /**
     * Gera o QR de autorização (primeiro mês). Uma autorização antiga ainda
     * não concluída (CREATED, QR expirado ou perdido) é cancelada antes, para
     * não sobrar autorização solta na Asaas.
     *
     * @return array{id: string, status: string, payload: ?string, qrImage: ?string, expiresAt: ?string}
     */
    public function startAuthorization(Subscription $assinatura): array
    {
        if ($assinatura->billing_type !== PaymentMethod::PixAutomatic || $assinatura->status === SubscriptionStatus::Cancelled) {
            throw new DomainException('Esta assinatura não aceita Pix Automático.');
        }

        if ($assinatura->hasActivePixAuthorization()) {
            throw new DomainException('O débito automático já está ativo.');
        }

        if ($assinatura->asaas_pix_authorization_id !== null && $assinatura->pix_authorization_status === 'CREATED') {
            $this->cancelQuietly($assinatura->asaas_pix_authorization_id);
        }

        $customerId = $this->asaas->findOrCreateCustomer($assinatura->user);
        $autorizacao = $this->asaas->createPixAuthorization($customerId, $assinatura->id, $assinatura->monthlyPrice());

        $assinatura->update([
            'asaas_pix_authorization_id' => $autorizacao['id'],
            'pix_authorization_status' => $autorizacao['status'] ?: 'CREATED',
        ]);

        return $autorizacao;
    }

    /** Cancela a autorização na Asaas (cancelamento da assinatura). Falha lá não impede o cancelamento aqui. */
    public function cancelAuthorization(Subscription $assinatura): void
    {
        if ($assinatura->asaas_pix_authorization_id === null) {
            return;
        }

        $this->cancelQuietly($assinatura->asaas_pix_authorization_id);
        $assinatura->update(['pix_authorization_status' => 'CANCELLED']);
    }

    /**
     * Cria a cobrança do próximo vencimento de quem tem débito automático
     * ativo e vence nos próximos dias.
     *
     * @return int cobranças criadas na Asaas nesta execução
     */
    public function createUpcomingCharges(): int
    {
        $hoje = Carbon::today();

        $assinaturas = Subscription::query()
            ->where('billing_type', PaymentMethod::PixAutomatic)
            ->where('status', SubscriptionStatus::Active)
            ->where('pix_authorization_status', 'ACTIVE')
            ->whereBetween('current_period_ends_at', [$hoje->toDateString(), $hoje->copy()->addDays(self::DIAS_DE_ANTECEDENCIA)->toDateString()])
            ->with('user')
            ->get();

        $criadas = 0;

        foreach ($assinaturas as $assinatura) {
            $vencimento = $assinatura->current_period_ends_at;
            $cobranca = $this->reservarCiclo($assinatura, $vencimento);

            if ($cobranca === null) {
                continue; // já cobrado, ou outra execução está cuidando
            }

            try {
                $paymentId = $this->asaas->createPixAutomaticCharge(
                    $this->asaas->findOrCreateCustomer($assinatura->user),
                    $assinatura->asaas_pix_authorization_id,
                    $assinatura->monthlyPrice(),
                    $vencimento->toDateString(),
                    self::externalReference($assinatura, $vencimento),
                );

                $cobranca->update(['asaas_payment_id' => $paymentId]);
                $criadas++;
            } catch (\Throwable $e) {
                // A reserva expira sozinha e a próxima execução tenta de novo.
                $cobranca->update(['claimed_at' => null]);
                Log::warning('Pix Automático: não conseguiu criar a cobrança do ciclo', [
                    'subscription_id' => $assinatura->id,
                    'vencimento' => $vencimento->toDateString(),
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        return $criadas;
    }

    /**
     * Pede à Asaas as retentativas registradas pelos webhooks de recusa. Fica
     * num job (e não no webhook) porque a Asaas rejeita pedido feito no dia da
     * própria data pedida: rodando de manhã, a data de retentativa (2, 4 ou 6
     * dias após o vencimento) ainda está à frente.
     *
     * @return int retentativas pedidas
     */
    public function requestPendingRetries(): int
    {
        $hoje = Carbon::today();

        $pendentes = SubscriptionCharge::query()
            ->whereNotNull('retry_due_date')
            ->whereNull('retry_requested_at')
            ->whereNull('paid_at')
            ->whereNotNull('asaas_instruction_id')
            ->get();

        $pedidas = 0;

        foreach ($pendentes as $cobranca) {
            if (! $cobranca->retry_due_date->isAfter($hoje)) {
                Log::warning('Pix Automático: retentativa perdeu a data e foi descartada', ['charge_id' => $cobranca->id]);
                $cobranca->update(['retry_due_date' => null]);

                continue;
            }

            try {
                $this->asaas->retryPixInstruction($cobranca->asaas_instruction_id, $cobranca->retry_due_date->toDateString());
                $cobranca->update(['retry_requested_at' => now()]);
                $pedidas++;
            } catch (\Throwable $e) {
                Log::warning('Pix Automático: a Asaas recusou o pedido de retentativa', [
                    'charge_id' => $cobranca->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        return $pedidas;
    }

    /** Referência que volta no webhook de pagamento: `cerne:{assinatura}:{ano-mês do vencimento}`. */
    public static function externalReference(Subscription $assinatura, CarbonInterface $vencimento): string
    {
        return 'cerne:'.$assinatura->id.':'.$vencimento->format('Y-m');
    }

    /**
     * Reserva o ciclo. Devolve a linha só para quem de fato pode chamar a
     * Asaas: a primeira execução (insere) ou uma que retoma uma reserva que
     * nunca chegou a criar a cobrança. Quem perde a corrida recebe null.
     */
    private function reservarCiclo(Subscription $assinatura, CarbonInterface $vencimento): ?SubscriptionCharge
    {
        try {
            return SubscriptionCharge::create([
                'subscription_id' => $assinatura->id,
                'year' => $vencimento->year,
                'month' => $vencimento->month,
                'due_date' => $vencimento->toDateString(),
                'value' => $assinatura->monthlyPrice(),
                'claimed_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existente = SubscriptionCharge::query()
                ->where('subscription_id', $assinatura->id)
                ->where('year', $vencimento->year)
                ->where('month', $vencimento->month)
                ->first();

            if ($existente === null || $existente->asaas_payment_id !== null) {
                return null;
            }

            // Retoma só se a reserva anterior está solta ou velha; o UPDATE
            // condicional é o que decide o vencedor quando duas execuções
            // chegam juntas.
            $ganhou = SubscriptionCharge::query()
                ->whereKey($existente->id)
                ->whereNull('asaas_payment_id')
                ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(self::RESERVA_MINUTOS)))
                ->update(['claimed_at' => now()]);

            return $ganhou === 1 ? $existente->refresh() : null;
        }
    }

    private function cancelQuietly(string $authorizationId): void
    {
        try {
            $this->asaas->cancelPixAuthorization($authorizationId);
        } catch (\Throwable $e) {
            Log::warning('Pix Automático: não conseguiu cancelar a autorização na Asaas', [
                'authorization_id' => $authorizationId,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
