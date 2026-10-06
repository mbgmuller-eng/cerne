<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionNotice;
use App\Notifications\PixAutomaticActivationDue;
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
     * Pix Automático: o teste grátis acaba em 3 dias e o débito ainda não foi
     * autorizado. O QR de autorização cobra o primeiro mês, então sem esse
     * passo a pessoa perde o acesso no fim do teste.
     */
    public function notifyPixAutomaticActivation(): int
    {
        $fimDoTeste = Carbon::today()->addDays(self::DIAS_DE_ANTECEDENCIA);

        $assinaturas = Subscription::query()
            ->where('billing_type', PaymentMethod::PixAutomatic)
            ->where('status', SubscriptionStatus::Trialing)
            ->whereDate('current_period_ends_at', $fimDoTeste)
            ->where(fn ($q) => $q->whereNull('pix_authorization_status')->orWhere('pix_authorization_status', '!=', 'ACTIVE'))
            ->with('user')
            ->get();

        foreach ($assinaturas as $assinatura) {
            $assinatura->user->notify(new PixAutomaticActivationDue(
                $assinatura->bundle->label(),
                $fimDoTeste->translatedFormat('d \\d\\e F'),
                \App\Support\Money::format($assinatura->monthlyPrice()),
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
        // A carência varia por forma de pagamento (Pix Automático tem mais dias
        // por causa das retentativas), então pega a faixa possível de
        // vencimentos e confere o corte de cada assinatura.
        $assinaturas = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Trialing])
            ->whereBetween('current_period_ends_at', [
                $amanha->copy()->subDays(Subscription::PIX_AUTOMATIC_GRACE_DAYS)->toDateString(),
                $amanha->copy()->subDays(Subscription::PAST_DUE_GRACE_DAYS)->toDateString(),
            ])
            ->with('user')
            ->get()
            ->filter(fn (Subscription $assinatura) => $assinatura->isCurrent() && $assinatura->accessCutoffDate()->isSameDay($amanha));

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
