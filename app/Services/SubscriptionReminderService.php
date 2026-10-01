<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Subscription;
use App\Notifications\PixPaymentDueSoon;
use Illuminate\Support\Carbon;

/**
 * Pix não tem débito automático — a Asaas gera uma cobrança nova a cada
 * ciclo (ou no fim do teste grátis, mesma mecânica), mas ninguém paga
 * sozinho sem ver o QR code. Roda todo dia, avisa quem vence em 3 dias
 * exatos: cedo o bastante pra não pegar ninguém de surpresa, tarde o
 * bastante pra não virar ruído mensal repetido.
 */
class SubscriptionReminderService
{
    private const DIAS_DE_ANTECEDENCIA = 3;

    public function notifyUpcomingPixDueDates(AsaasClient $asaas): int
    {
        $vencimento = Carbon::today()->addDays(self::DIAS_DE_ANTECEDENCIA);

        $assinaturas = Subscription::query()
            ->where('billing_type', PaymentMethod::Pix)
            ->whereDate('current_period_ends_at', $vencimento)
            ->with('user')
            ->get()
            ->filter(fn (Subscription $assinatura) => $assinatura->isCurrent());

        foreach ($assinaturas as $assinatura) {
            $invoiceUrl = $assinatura->asaas_subscription_id !== null
                ? $asaas->currentInvoiceUrl($assinatura->asaas_subscription_id)
                : null;

            $assinatura->user->notify(new PixPaymentDueSoon(
                $assinatura->bundle->label(),
                $vencimento->translatedFormat('d \d\e F'),
                $invoiceUrl,
            ));
        }

        return $assinaturas->count();
    }
}
