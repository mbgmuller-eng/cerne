<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\FinancialProfile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cobrança por pessoa acompanhada: cada uma soma `billing.care_seat_price` ao valor mensal da assinatura DIRETA do dono (ver
 * Subscription::monthlyPrice()). Quem cuida junto nunca paga.
 *
 * Onde o valor é fixo na Asaas (cartão e boleto, assinatura recorrente), este serviço troca o valor da assinatura. No Pix Automático
 * não há o que trocar: o Cerne calcula o valor de cada cobrança na hora de criá-la (modo MANUAL), então o novo valor já vale no ciclo seguinte.
 * Em qualquer dos dois, o novo valor vale a partir da PRÓXIMA cobrança: o ciclo já pago não é cobrado de novo, e não há valor proporcional.
 *
 * Falhar ao avisar a Asaas não impede cadastrar nem apagar a pessoa (a Asaas fora do ar não pode travar o cuidado de ninguém): a diferença fica
 * em `care_seats_billed` e a rotina `assentos-de-cuidado` a corrige (idempotente: só age quando a contagem mudou).
 */
class CareBillingService
{
    public function __construct(private readonly AsaasClient $asaas) {}

    /** Preço de uma pessoa acompanhada por mês ("4.90"). */
    public static function seatPrice(): string
    {
        return number_format((float) config('billing.care_seat_price', '4.90'), 2, '.', '');
    }

    /** Quantas pessoas acompanhadas o usuário tem hoje (cada uma é um assento cobrado). */
    public static function seatsOf(User $owner): int
    {
        return FinancialProfile::withCareProfiles()
            ->where('owner_user_id', $owner->id)
            ->where('profile_type', \App\Enums\ProfileType::Care->value)
            ->count();
    }

    /**
     * Alinha o que a Asaas cobra ao número de pessoas de hoje. Devolve true se havia diferença a corrigir.
     * Quem chama decide o que fazer com uma falha (a rotina registra e tenta de novo no dia seguinte).
     */
    public function sync(User $owner): bool
    {
        $assinatura = $this->directSubscription($owner);

        if ($assinatura === null) {
            return false;
        }

        $assentos = self::seatsOf($owner);

        if ($assentos === (int) $assinatura->care_seats_billed) {
            return false;
        }

        // Pix Automático e assinatura ainda sem cobrança na Asaas (teste grátis) calculam o valor na hora de cobrar.
        if ($assinatura->asaas_subscription_id !== null && $assinatura->billing_type !== PaymentMethod::PixAutomatic) {
            $this->asaas->updateSubscriptionValue($assinatura->asaas_subscription_id, $assinatura->monthlyPrice());
        }

        $assinatura->update(['care_seats_billed' => $assentos]);

        return true;
    }

    /** O mesmo de sync(), sem deixar uma falha subir: registra e segue (a rotina diária corrige). */
    public function syncQuietly(User $owner): void
    {
        try {
            $this->sync($owner);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Cobrança por pessoa acompanhada: não conseguiu avisar a Asaas agora; a rotina diária tenta de novo', [
                'user_id' => $owner->id, 'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Rotina diária: corrige toda assinatura direta cujo número de pessoas mudou desde o último aviso à Asaas.
     *
     * @return int quantas assinaturas foram corrigidas
     */
    public function reconcile(): int
    {
        $corrigidas = 0;

        Subscription::query()
            ->ofKind(SubscriptionKind::Direct)
            ->where('status', '!=', SubscriptionStatus::Cancelled->value)
            ->with('user')
            ->orderBy('id')
            ->each(function (Subscription $assinatura) use (&$corrigidas): void {
                if ($assinatura->user === null) {
                    return;
                }

                // Só mexe em quem tem ou tinha assento: o resto da base nem entra na conta.
                if ((int) $assinatura->care_seats_billed === 0 && self::seatsOf($assinatura->user) === 0) {
                    return;
                }

                try {
                    if ($this->sync($assinatura->user)) {
                        $corrigidas++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $corrigidas;
    }

    private function directSubscription(User $owner): ?Subscription
    {
        return Subscription::query()
            ->ofKind(SubscriptionKind::Direct)
            ->where('user_id', $owner->id)
            ->where('status', '!=', SubscriptionStatus::Cancelled->value)
            ->latest('created_at')
            ->first();
    }
}
