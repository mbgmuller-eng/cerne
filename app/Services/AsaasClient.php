<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Models\User;
use App\Support\ProfessionalPricing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Wrapper fino sobre a API REST da Asaas — sem SDK de terceiro (não existe
 * pacote oficial maduro pra PHP). Autenticação de SAÍDA é o header
 * `access_token`; a autenticação do webhook de ENTRADA é outra coisa (ver
 * AsaasWebhookController) e não passa por aqui.
 */
class AsaasClient
{
    /** Cliente já existe (Asaas permite duplicado — por isso Cerne guarda o id, não cria de novo toda hora). */
    public function findOrCreateCustomer(User $user): string
    {
        if ($user->asaas_customer_id !== null) {
            return $user->asaas_customer_id;
        }

        if (blank($user->cpf_cnpj)) {
            throw new RuntimeException('Usuário sem CPF/CNPJ cadastrado — obrigatório pra Asaas criar o cliente.');
        }

        $resposta = $this->request()->post('/customers', [
            'name' => $user->name,
            'email' => $user->email,
            'cpfCnpj' => preg_replace('/\D/', '', $user->cpf_cnpj),
            'phone' => $user->phone,
            'externalReference' => $user->id,
        ])->throw();

        $customerId = $resposta->json('id');
        $user->update(['asaas_customer_id' => $customerId]);

        return $customerId;
    }

    /**
     * `nextDueDate` 7 dias à frente é o teste grátis inteiro — não existe
     * campo "trial" na Asaas, é só a primeira cobrança nascer mais tarde.
     * Cartão e Pix tratados igual (`asaasBillingType()`): nenhum dos dois
     * cobra sozinho hoje, os dois clicam a fatura — cobrança automática de
     * cartão de verdade exigiria tokenizar o cartão pelo nosso backend e
     * depende de aprovação da Asaas pra produção (ver o plano).
     *
     * $clientCap preenchido = assinatura de profissional: o valor vem só do
     * teto de clientes (ProfessionalPricing), sem olhar o pacote. Nulo =
     * assinatura direta, valor do pacote.
     *
     * @return array{id: string, invoiceUrl: ?string}
     */
    public function createSubscription(
        string $customerId,
        SubscriptionBundle $bundle,
        PaymentMethod $metodoPagamento,
        string $descricao,
        ?int $clientCap = null,
    ): array {
        $valor = $clientCap !== null
            ? (float) ProfessionalPricing::priceFor($clientCap)
            : config("billing.prices.{$bundle->value}");

        $resposta = $this->request()->post('/subscriptions', [
            'customer' => $customerId,
            'billingType' => $metodoPagamento->asaasBillingType(),
            'nextDueDate' => now()->addDays(7)->toDateString(),
            'value' => $valor,
            'cycle' => 'MONTHLY',
            'description' => $descricao,
        ])->throw();

        return [
            'id' => $resposta->json('id'),
            'invoiceUrl' => $this->firstInvoiceUrl($resposta->json('id')),
        ];
    }

    /**
     * Muda o valor das próximas cobranças de uma assinatura existente (a
     * Asaas aceita PUT em /subscriptions/{id}). `updatePendingPayments`
     * também atualiza a cobrança já gerada: a Asaas cria cada cobrança com
     * até 40 dias de antecedência, então sem isso o novo valor só valeria
     * um ciclo depois do esperado.
     */
    public function updateSubscriptionValue(string $asaasSubscriptionId, string $valor): void
    {
        $this->request()->put("/subscriptions/{$asaasSubscriptionId}", [
            'value' => (float) $valor,
            'updatePendingPayments' => true,
        ])->throw();
    }

    public function cancelSubscription(string $asaasSubscriptionId): void
    {
        $this->request()->delete("/subscriptions/{$asaasSubscriptionId}")->throw();
    }

    /**
     * A assinatura em si não carrega invoiceUrl — é a primeira cobrança
     * gerada por ela que tem. Busca a cobrança mais recente dessa
     * assinatura pra mandar a pessoa direto pra fatura.
     */
    private function firstInvoiceUrl(string $asaasSubscriptionId): ?string
    {
        try {
            $resposta = $this->request()->get("/subscriptions/{$asaasSubscriptionId}/payments")->throw();

            return $resposta->json('data.0.invoiceUrl');
        } catch (\Throwable $e) {
            Log::warning('Asaas: não achou a fatura inicial da assinatura', ['subscription_id' => $asaasSubscriptionId, 'erro' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Link da cobrança PENDENTE mais recente — diferente de
     * firstInvoiceUrl(), que sempre pega a primeira. Usado pelo lembrete
     * de Pix (SubscriptionReminderService), onde a cobrança relevante é a
     * do ciclo atual, não a do dia em que a assinatura nasceu.
     */
    public function currentInvoiceUrl(string $asaasSubscriptionId): ?string
    {
        try {
            $resposta = $this->request()
                ->get("/subscriptions/{$asaasSubscriptionId}/payments", ['status' => 'PENDING'])
                ->throw();

            return $resposta->json('data.0.invoiceUrl');
        } catch (\Throwable $e) {
            Log::warning('Asaas: não achou a fatura pendente da assinatura', ['subscription_id' => $asaasSubscriptionId, 'erro' => $e->getMessage()]);

            return null;
        }
    }

    private function request()
    {
        return Http::baseUrl(config('services.asaas.base_url'))
            ->withHeaders(['access_token' => config('services.asaas.api_key')])
            ->acceptJson();
    }
}
