<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Exceptions\AsaasBillingTypeMismatch;
use App\Services\AsaasClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Boleto não é forma de pagamento do Cerne. Em produção a primeira cobrança Pix
 * da conta saiu como BOLETO (a chave Pix da Asaas ainda estava sendo criada),
 * gerando um boleto real no CPF da pessoa. A chamada agora confere a resposta e
 * desfaz a assinatura se vier outra forma que não a pedida.
 */
class AsaasBillingTypeGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.asaas.base_url' => 'https://asaas.test/v3', 'services.asaas.api_key' => 'chave-de-teste']);
    }

    private function criar(PaymentMethod $metodo): array
    {
        return app(AsaasClient::class)->createSubscription('cus_1', SubscriptionBundle::SaudeDocumentos, $metodo, 'Cerne', null, '2026-10-13', 'ref-1');
    }

    public function test_pix_pedido_e_pix_recebido_segue_normalmente_e_nunca_envia_boleto(): void
    {
        Http::fake(['asaas.test/v3/subscriptions' => Http::response(['id' => 'sub_1', 'billingType' => 'PIX'])]);

        self::assertSame(['id' => 'sub_1'], $this->criar(PaymentMethod::Pix));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['billingType'] === 'PIX');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
        Http::assertNotSent(fn (Request $r) => ($r->data()['billingType'] ?? null) === 'BOLETO');
    }

    public function test_envia_o_vencimento_e_a_referencia_e_busca_pela_referencia(): void
    {
        Http::fake([
            'asaas.test/v3/subscriptions?*' => Http::response(['data' => [['id' => 'sub_9']]]),
            'asaas.test/v3/subscriptions' => Http::response(['id' => 'sub_1', 'billingType' => 'PIX']),
        ]);

        $this->criar(PaymentMethod::Pix);
        self::assertSame('sub_9', app(AsaasClient::class)->findSubscriptionIdByReference('ref-1'));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['nextDueDate'] === '2026-10-13' && $r['externalReference'] === 'ref-1');
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'externalReference=ref-1'));
    }

    public function test_cartao_pedido_e_cartao_recebido_segue_normalmente(): void
    {
        Http::fake(['asaas.test/v3/subscriptions' => Http::response(['id' => 'sub_2', 'billingType' => 'CREDIT_CARD'])]);

        self::assertSame(['id' => 'sub_2'], $this->criar(PaymentMethod::CreditCard));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['billingType'] === 'CREDIT_CARD');
    }

    public function test_boleto_devolvido_pela_asaas_desfaz_a_assinatura_e_falha(): void
    {
        Http::fake([
            'asaas.test/v3/subscriptions/sub_1' => Http::response(['deleted' => true]),
            'asaas.test/v3/subscriptions' => Http::response(['id' => 'sub_1', 'billingType' => 'BOLETO']),
        ]);

        try {
            $this->criar(PaymentMethod::Pix);
            self::fail('Era esperado AsaasBillingTypeMismatch.');
        } catch (AsaasBillingTypeMismatch $e) {
            self::assertSame('PIX', $e->pedido);
            self::assertSame('BOLETO', $e->recebido);
        }

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/subscriptions/sub_1'));
    }

    public function test_falha_ao_desfazer_nao_esconde_o_erro_original(): void
    {
        Http::fake([
            'asaas.test/v3/subscriptions/sub_1' => Http::response(['errors' => []], 500),
            'asaas.test/v3/subscriptions' => Http::response(['id' => 'sub_1', 'billingType' => 'BOLETO']),
        ]);

        $this->expectException(AsaasBillingTypeMismatch::class);

        $this->criar(PaymentMethod::CreditCard);
    }
}
