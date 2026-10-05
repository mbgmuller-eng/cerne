<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionNotice;
use App\Notifications\PixPaymentDueSoon;
use App\Notifications\SubscriptionAccessEnding;
use Illuminate\Database\UniqueConstraintViolationException;
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

    private const AVISO_ACESSO_ENCERRA = 'access_ending';

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

    /**
     * Último dia da carência de quem está em atraso: o acesso é cortado
     * amanhã (Subscription::accessCutoffDate()). Roda todo dia; o índice
     * único em subscription_notices garante um aviso só mesmo se o cron
     * disparar duas vezes no mesmo dia.
     */
    public function notifyAccessEndingTomorrow(AsaasClient $asaas): int
    {
        $amanha = Carbon::tomorrow();
        $vencimento = $amanha->copy()->subDays(Subscription::PAST_DUE_GRACE_DAYS);

        $assinaturas = Subscription::query()
            ->where('status', SubscriptionStatus::PastDue)
            ->whereDate('current_period_ends_at', $vencimento)
            ->with('user')
            ->get()
            ->filter(fn (Subscription $assinatura) => $assinatura->isCurrent());

        $avisados = 0;

        foreach ($assinaturas as $assinatura) {
            try {
                SubscriptionNotice::create([
                    'subscription_id' => $assinatura->id,
                    'kind' => self::AVISO_ACESSO_ENCERRA,
                    'reference_date' => $amanha,
                    'sent_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $invoiceUrl = $assinatura->asaas_subscription_id !== null
                ? $asaas->currentInvoiceUrl($assinatura->asaas_subscription_id)
                : null;

            $assinatura->user->notify(new SubscriptionAccessEnding(
                $assinatura->bundle->label(),
                $amanha->format('d/m/Y'),
                $invoiceUrl,
            ));

            $avisados++;
        }

        return $avisados;
    }
}
