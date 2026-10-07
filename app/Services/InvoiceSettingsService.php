<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * Liga a emissão automática de nota fiscal de serviço (NFS-e) nas assinaturas
 * da Asaas: a nota sai sozinha quando cada pagamento é confirmado.
 *
 * Desligado por BILLING_ISSUE_INVOICES até a parte fiscal da conta da Asaas
 * (certificado digital, inscrição municipal, serviço e impostos) estar pronta.
 * A falha aqui NUNCA trava o pagamento: fica registrada, e a tarefa diária
 * (configurePending) tenta de novo até dar certo.
 */
class InvoiceSettingsService
{
    public function __construct(private readonly AsaasClient $asaas) {}

    public static function enabled(): bool
    {
        return (bool) config('billing.invoices.enabled');
    }

    /** Serviço municipal e alíquota de ISS definidos: o mínimo para a Asaas aceitar a configuração. */
    public static function isConfigured(): bool
    {
        $cfg = config('billing.invoices');

        return (filled($cfg['municipal_service_id'] ?? null) || filled($cfg['municipal_service_code'] ?? null))
            && ($cfg['taxes']['iss'] ?? null) !== null;
    }

    /** @return bool true se a configuração ficou aplicada nesta assinatura. */
    public function configure(Subscription $assinatura): bool
    {
        if (! self::enabled() || $assinatura->asaas_subscription_id === null || $assinatura->invoice_settings_at !== null) {
            return false;
        }

        if (! self::isConfigured()) {
            Log::error('Nota fiscal: emissão ligada, mas falta o serviço municipal ou o ISS em config/billing.php (BILLING_NF_*)');

            return false;
        }

        try {
            $this->asaas->configureSubscriptionInvoices($assinatura->asaas_subscription_id, config('billing.invoices'));
        } catch (\Throwable $e) {
            Log::error('Nota fiscal: não conseguiu configurar a emissão na assinatura da Asaas', [
                'subscription_id' => $assinatura->id,
                'asaas_subscription_id' => $assinatura->asaas_subscription_id,
                'erro' => $e->getMessage(),
            ]);

            return false;
        }

        Subscription::query()->whereKey($assinatura->id)->update(['invoice_settings_at' => now()]);

        return true;
    }

    /**
     * Tarefa diária: aplica a configuração nas assinaturas que ficaram sem ela
     * (a Asaas estava fora do ar, ou a emissão foi ligada depois).
     *
     * Pix Automático fica de fora: as cobranças dele são criadas uma a uma pelo
     * Cerne, sem assinatura na Asaas, e a nota delas é agendada por cobrança.
     */
    public function configurePending(): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $feitas = 0;

        Subscription::query()
            ->whereNotNull('asaas_subscription_id')
            ->whereNull('invoice_settings_at')
            ->where('status', '!=', SubscriptionStatus::Cancelled)
            ->whereIn('billing_type', [PaymentMethod::CreditCard, PaymentMethod::Pix])
            ->each(function (Subscription $assinatura) use (&$feitas): void {
                if ($this->configure($assinatura)) {
                    $feitas++;
                }
            });

        return $feitas;
    }
}
