<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Exceptions\AsaasBillingTypeMismatch;
use App\Models\BillingDetail;
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
    /**
     * Cliente da Asaas (quem recebe a cobrança). Reaproveita o id guardado: a
     * Asaas permite duplicado, por isso o Cerne guarda o id em vez de criar de novo.
     *
     * Com os dados fiscais (`$fiscal`), o cliente é criado, ou ATUALIZADO se já
     * existe, com nome completo, CPF/CNPJ e endereço: é daí que a Asaas tira o
     * tomador da nota fiscal.
     */
    public function findOrCreateCustomer(User $user, ?BillingDetail $fiscal = null): string
    {
        if ($user->asaas_customer_id !== null) {
            if ($fiscal !== null) {
                $this->request()->post("/customers/{$user->asaas_customer_id}", $fiscal->asaasCustomerFields())->throw();
            }

            return $user->asaas_customer_id;
        }

        $documento = $fiscal?->document ?? preg_replace('/\D/', '', (string) $user->cpf_cnpj);

        if (blank($documento)) {
            throw new RuntimeException('Usuário sem CPF/CNPJ cadastrado — obrigatório para a Asaas criar o cliente.');
        }

        $resposta = $this->request()->post('/customers', array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'cpfCnpj' => $documento,
            'phone' => $user->phone,
            'externalReference' => $user->id,
        ], $fiscal?->asaasCustomerFields() ?? []))->throw();

        $customerId = $resposta->json('id');
        $user->update(['asaas_customer_id' => $customerId]);

        return $customerId;
    }

    /**
     * Emissão automática de NFS-e na confirmação de cada pagamento da assinatura.
     * A Asaas exige o bloco `taxes`; o serviço municipal vai por id OU por código.
     *
     * @param  array<string, mixed>  $config  config('billing.invoices')
     */
    public function configureSubscriptionInvoices(string $asaasSubscriptionId, array $config): void
    {
        $impostos = $config['taxes'];

        $corpo = array_filter([
            'municipalServiceId' => $config['municipal_service_id'] ?? null,
            'municipalServiceCode' => $config['municipal_service_code'] ?? null,
            'municipalServiceName' => $config['municipal_service_name'] ?? null,
            'effectiveDatePeriod' => 'ON_PAYMENT_CONFIRMATION',
            'observations' => $config['observations'] ?? null,
        ], fn ($valor) => $valor !== null && $valor !== '');

        $corpo['taxes'] = [
            'retainIss' => (bool) $impostos['retain_iss'],
            'iss' => (float) $impostos['iss'],
            'cofins' => (float) $impostos['cofins'],
            'csll' => (float) $impostos['csll'],
            'inss' => (float) $impostos['inss'],
            'ir' => (float) $impostos['ir'],
            'pis' => (float) $impostos['pis'],
        ];

        $this->request()->post("/subscriptions/{$asaasSubscriptionId}/invoiceSettings", $corpo)->throw();
    }
    /**
     * Chamada só perto do fim do teste grátis (SubscriptionBillingService):
     * a Asaas cria a primeira cobrança NA HORA da criação, com vencimento em
     * `$primeiroVencimento`, então criar a assinatura no cadastro geraria a
     * cobrança (e o aviso ao cliente) uma semana antes do necessário.
     * Cartão e Pix tratados igual (`asaasBillingType()`): nenhum dos dois
     * cobra sozinho, os dois clicam a fatura — cobrança automática de
     * cartão de verdade exigiria tokenizar o cartão pelo nosso backend e
     * depende de aprovação da Asaas pra produção (ver o plano).
     *
     * `$externalReference` é o id da assinatura do Cerne: permite achar de novo,
     * sem duplicar, uma assinatura que a Asaas criou mas o Cerne não chegou a
     * gravar (ver findSubscriptionIdByReference()).
     *
     * $clientCap preenchido = assinatura de profissional: o valor vem só do
     * teto de clientes (ProfessionalPricing), sem olhar o pacote. Nulo =
     * assinatura direta, valor do pacote.
     *
     * Devolve só o id: a pessoa NÃO é levada à fatura. A Asaas cria a primeira
     * cobrança na hora, com vencimento em `nextDueDate` (confirmado em produção:
     * mesmo com a data a 60 dias ela gera a cobrança já no ato), então abrir a
     * fatura aqui parecia cobrança imediata. O link chega por e-mail (Asaas e
     * lembretes do Cerne) perto do vencimento.
     *
     * Boleto NUNCA: a forma é sempre enviada explícita (PIX ou CREDIT_CARD) e a
     * resposta é conferida. Se a Asaas devolver outra (aconteceu na primeira
     * cobrança Pix da conta, enquanto a chave Pix ainda não estava ativa: saiu
     * BOLETO), a assinatura é cancelada na hora, antes de qualquer cobrança
     * ser paga, e a chamada falha.
     *
     * @return array{id: string}
     * @throws AsaasBillingTypeMismatch
     */
    public function createSubscription(
        string $customerId,
        SubscriptionBundle $bundle,
        PaymentMethod $metodoPagamento,
        string $descricao,
        ?int $clientCap,
        string $primeiroVencimento,
        ?string $externalReference = null,
    ): array {
        $valor = $clientCap !== null
            ? (float) ProfessionalPricing::priceFor($clientCap)
            : config("billing.prices.{$bundle->value}");

        $resposta = $this->request()->post('/subscriptions', [
            'customer' => $customerId,
            'billingType' => $metodoPagamento->asaasBillingType(),
            'nextDueDate' => $primeiroVencimento,
            'value' => $valor,
            'cycle' => 'MONTHLY',
            'description' => $descricao,
            'externalReference' => $externalReference,
        ])->throw();

        $id = (string) $resposta->json('id');
        $pedido = $metodoPagamento->asaasBillingType();
        $recebido = (string) $resposta->json('billingType');

        if ($recebido !== $pedido) {
            try {
                $this->cancelSubscription($id);
            } catch (\Throwable $e) {
                // Não dá pra seguir com uma assinatura de forma errada viva na Asaas: fica no log como crítico.
                Log::critical('Asaas: assinatura criada com forma de pagamento errada e NÃO foi cancelada', [
                    'subscription_id' => $id, 'pedido' => $pedido, 'recebido' => $recebido, 'erro' => $e->getMessage(),
                ]);
            }

            throw new AsaasBillingTypeMismatch($pedido, $recebido);
        }

        return ['id' => $id];
    }

    /** Id da assinatura (não apagada) criada com esta referência, ou null. A Asaas ignora as apagadas. */
    public function findSubscriptionIdByReference(string $externalReference): ?string
    {
        return $this->request()
            ->get('/subscriptions', ['externalReference' => $externalReference, 'limit' => 1])
            ->throw()
            ->json('data.0.id');
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
     * Link da cobrança PENDENTE mais recente — diferente de
     * o antigo link da primeira. Usado pelo lembrete
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

    /**
     * Pix Automático: cria a autorização, cujo QR Code cobra o primeiro mês e
     * pede ao banco do cliente o consentimento para os débitos seguintes. Modo
     * MANUAL de propósito: o valor de cada mês é calculado pelo Cerne na hora
     * de cobrar, então um aumento de faixa vale no ciclo seguinte (no modo
     * SUBSCRIPTION o valor ficaria fixo na autorização).
     *
     * `contractId` aceita no máximo 35 caracteres, e um UUID com hífens tem 36.
     * A resposta traz o "copia e cola" e a imagem do QR enquanto a autorização
     * está CREATED (depois de cancelada vêm nulos).
     *
     * @return array{id: string, status: string, payload: ?string, qrImage: ?string, expiresAt: ?string}
     */
    public function createPixAuthorization(string $customerId, string $subscriptionId, string $valorPrimeiraCobranca): array
    {
        $resposta = $this->request()->post('/pix/automatic/authorizations', [
            'customerId' => $customerId,
            'frequency' => 'MONTHLY',
            'contractId' => str_replace('-', '', $subscriptionId),
            'startDate' => now()->addDay()->toDateString(),
            'description' => 'Assinatura Cerne',
            'paymentCreationMode' => 'MANUAL',
            // Até 3 retentativas em dias diferentes, dentro de 7 dias do vencimento.
            'retryPolicy' => 'ALLOW_THREE_IN_SEVEN_DAYS',
            'immediateQrCode' => [
                'expirationSeconds' => 86400,
                'originalValue' => (float) $valorPrimeiraCobranca,
            ],
        ])->throw();

        return $this->pixAuthorizationData($resposta->json());
    }

    /** @return array{id: string, status: string, payload: ?string, qrImage: ?string, expiresAt: ?string} */
    public function getPixAuthorization(string $authorizationId): array
    {
        return $this->pixAuthorizationData($this->request()->get("/pix/automatic/authorizations/{$authorizationId}")->throw()->json());
    }

    public function cancelPixAuthorization(string $authorizationId): void
    {
        $this->request()->delete("/pix/automatic/authorizations/{$authorizationId}")->throw();
    }

    /**
     * Cobrança do ciclo vinculada à autorização. A Asaas exige a autorização
     * ATIVA e a criação entre 2 e 10 dias úteis antes do vencimento.
     * `externalReference` é como o webhook de pagamento volta até a assinatura
     * (cobrança manual não traz o id de uma assinatura da Asaas).
     */
    public function createPixAutomaticCharge(string $customerId, string $authorizationId, string $valor, string $vencimento, string $externalReference): string
    {
        return (string) $this->request()->post('/payments', [
            'customer' => $customerId,
            'billingType' => 'PIX',
            'value' => (float) $valor,
            'dueDate' => $vencimento,
            'description' => 'Assinatura Cerne',
            'externalReference' => $externalReference,
            'pixAutomaticAuthorizationId' => $authorizationId,
        ])->throw()->json('id');
    }

    /** Nova tentativa de uma instrução recusada. A Asaas rejeita pedido feito no próprio dia da data pedida. */
    public function retryPixInstruction(string $instructionId, string $novoVencimento): void
    {
        $this->request()->post("/pix/automatic/paymentInstructions/{$instructionId}/retries", [
            'dueDate' => $novoVencimento,
        ])->throw();
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{id: string, status: string, payload: ?string, qrImage: ?string, expiresAt: ?string}
     */
    private function pixAuthorizationData(array $dados): array
    {
        return [
            'id' => (string) $dados['id'],
            'status' => (string) ($dados['status'] ?? ''),
            'payload' => $dados['payload'] ?? null,
            'qrImage' => $dados['encodedImage'] ?? null,
            'expiresAt' => $dados['immediateQrCode']['expirationDate'] ?? null,
        ];
    }

    private function request()
    {
        return Http::baseUrl(config('services.asaas.base_url'))
            ->withHeaders(['access_token' => config('services.asaas.api_key')])
            ->acceptJson();
    }
}
