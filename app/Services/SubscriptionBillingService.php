<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Exceptions\AsaasBillingTypeMismatch;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Cria na Asaas a assinatura de quem escolheu cartão ou Pix, só perto do fim
 * do teste grátis. No cadastro nada vai para a Asaas: a Asaas cria a primeira
 * cobrança no instante em que a assinatura nasce (com vencimento em
 * `nextDueDate`), e fazer isso no dia do cadastro gerava a cobrança, o aviso
 * ao cliente e até a fatura uma semana antes da hora.
 *
 * O vencimento é o último dia do teste (`current_period_ends_at`), mesmo
 * dia que a assinatura criada no cadastro já tinha. A criação acontece
 * DIAS_DE_ANTECEDENCIA antes, para a pessoa receber o link e ter tempo de
 * pagar; coloque 0 para criar só no dia do vencimento.
 *
 * Idempotente por estado, não por `if (existe)` (regra 4 do CLAUDE.md): a
 * reserva `billing_claimed_at` é um UPDATE condicional, e a referência
 * externa na Asaas impede duplicar uma assinatura que ela criou mas o Cerne
 * não chegou a gravar (queda entre as duas chamadas).
 *
 * Pix Automático não passa por aqui: ele tem autorização própria
 * (PixAutomaticBillingService).
 */
class SubscriptionBillingService
{
    public const DIAS_DE_ANTECEDENCIA = 3;

    /** Tempo em que uma reserva sem resposta da Asaas ainda bloqueia outra execução. */
    private const RESERVA_MINUTOS = 10;

    /** Depois de uma forma de pagamento errada, só tenta de novo no dia seguinte. */
    private const ESPERA_APOS_FORMA_ERRADA_HORAS = 23;

    public function __construct(private readonly AsaasClient $asaas) {}

    /** @return int assinaturas criadas na Asaas nesta execução */
    public function createDueSubscriptions(): int
    {
        $hoje = Carbon::today();

        $assinaturas = Subscription::query()
            ->whereIn('billing_type', [PaymentMethod::CreditCard, PaymentMethod::Pix])
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNull('asaas_subscription_id')
            ->whereDate('current_period_ends_at', '<=', $hoje->copy()->addDays(self::DIAS_DE_ANTECEDENCIA)->toDateString())
            ->with('user')
            ->get()
            // Teste abandonado há tempo (acesso já cortado) não vira cobrança.
            ->filter(fn (Subscription $assinatura) => $assinatura->isCurrent());

        $criadas = 0;

        foreach ($assinaturas as $assinatura) {
            if (! $this->reservar($assinatura)) {
                continue; // outra execução está cuidando
            }

            try {
                $id = $this->asaas->findSubscriptionIdByReference($assinatura->id)
                    ?? $this->criar($assinatura, $hoje);

                $this->gravar($assinatura, ['asaas_subscription_id' => $id, 'billing_claimed_at' => null]);
                $criadas++;
            } catch (AsaasBillingTypeMismatch $e) {
                // Já foi desfeita na Asaas. Não tenta de novo a cada execução: cada tentativa
                // geraria e cancelaria uma cobrança no banco da pessoa.
                $this->gravar($assinatura, ['billing_claimed_at' => now()->addHours(self::ESPERA_APOS_FORMA_ERRADA_HORAS)]);
                Log::error('Asaas: forma de pagamento diferente da pedida, assinatura desfeita', [
                    'subscription_id' => $assinatura->id, 'pedido' => $e->pedido, 'recebido' => $e->recebido,
                ]);
            } catch (\Throwable $e) {
                // A reserva é liberada e a próxima execução tenta de novo.
                $this->gravar($assinatura, ['billing_claimed_at' => null]);
                Log::warning('Asaas: não conseguiu criar a assinatura do fim do teste', [
                    'subscription_id' => $assinatura->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        return $criadas;
    }

    private function criar(Subscription $assinatura, Carbon $hoje): string
    {
        // Atrasou (cron parado): o vencimento é hoje, nunca uma data no passado.
        $vencimento = $assinatura->current_period_ends_at->lt($hoje) ? $hoje : $assinatura->current_period_ends_at;

        return $this->asaas->createSubscription(
            $this->asaas->findOrCreateCustomer($assinatura->user),
            $assinatura->bundle,
            $assinatura->billing_type,
            "Cerne — {$assinatura->bundle->label()}",
            $assinatura->client_cap,
            $vencimento->toDateString(),
            $assinatura->id,
        )['id'];
    }

    /**
     * Grava direto pela consulta: a reserva foi escrita por um UPDATE
     * condicional, então a cópia em memória ainda tem o valor antigo e o
     * Eloquent, vendo "nada mudou", não gravaria a liberação.
     *
     * @param  array<string, mixed>  $campos
     */
    private function gravar(Subscription $assinatura, array $campos): void
    {
        Subscription::query()->whereKey($assinatura->id)->update($campos);
    }

    private function reservar(Subscription $assinatura): bool
    {
        return Subscription::query()
            ->whereKey($assinatura->id)
            ->whereNull('asaas_subscription_id')
            ->where(fn ($q) => $q->whereNull('billing_claimed_at')->orWhere('billing_claimed_at', '<', now()->subMinutes(self::RESERVA_MINUTOS)))
            ->update(['billing_claimed_at' => now()]) === 1;
    }
}
