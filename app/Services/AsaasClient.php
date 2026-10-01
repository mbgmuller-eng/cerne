<?php

namespace App\Services;

use App\Enums\SubscriptionBundle;
use App\Models\User;
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

    /** @return array{id: string, invoiceUrl: ?string} */
    public function createSubscription(string $customerId, SubscriptionBundle $bundle, string $descricao): array
    {
        $resposta = $this->request()->post('/subscriptions', [
            'customer' => $customerId,
            'billingType' => 'UNDEFINED', // a pessoa escolhe Pix/boleto/cartão na fatura hospedada da Asaas
            'nextDueDate' => now()->addDay()->toDateString(),
            'value' => config("billing.prices.{$bundle->value}"),
            'cycle' => 'MONTHLY',
            'description' => $descricao,
        ])->throw();

        return [
            'id' => $resposta->json('id'),
            'invoiceUrl' => $this->firstInvoiceUrl($resposta->json('id')),
        ];
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

    private function request()
    {
        return Http::baseUrl(config('services.asaas.base_url'))
            ->withHeaders(['access_token' => config('services.asaas.api_key')])
            ->acceptJson();
    }
}
