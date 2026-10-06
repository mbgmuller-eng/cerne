<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Exceptions\AsaasBillingTypeMismatch;
use App\Livewire\Subscription\SubscriptionIndex;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AsaasClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
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
        return app(AsaasClient::class)->createSubscription('cus_1', SubscriptionBundle::SaudeDocumentos, $metodo, 'Cerne', null);
    }

    public function test_pix_pedido_e_pix_recebido_segue_normalmente_e_nunca_envia_boleto(): void
    {
        Http::fake(['asaas.test/v3/subscriptions' => Http::response(['id' => 'sub_1', 'billingType' => 'PIX'])]);

        self::assertSame(['id' => 'sub_1'], $this->criar(PaymentMethod::Pix));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['billingType'] === 'PIX');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
        Http::assertNotSent(fn (Request $r) => ($r->data()['billingType'] ?? null) === 'BOLETO');
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

    public function test_tela_nao_cria_assinatura_local_quando_a_asaas_devolve_forma_errada(): void
    {
        $usuario = User::factory()->create();
        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createSubscription')->andThrow(new AsaasBillingTypeMismatch('PIX', 'BOLETO'));
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', 'pix')
            ->call('assinar', 'saude_documentos')
            ->assertHasErrors('metodoPagamento')
            ->assertSee('Nenhuma cobrança foi gerada');

        self::assertSame(0, Subscription::query()->count());
    }

    public function test_assinar_nao_redireciona_para_a_fatura_e_explica_quando_vence(): void
    {
        $usuario = User::factory()->create();
        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createSubscription')->once()->andReturn(['id' => 'sub_1']);
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', 'pix')
            ->call('assinar', 'saude_documentos')
            ->assertNoRedirect()
            ->assertSee('Não há nada a pagar agora')
            ->assertSee(now()->addDays(7)->format('d/m/Y'));

        $assinatura = Subscription::query()->sole();
        self::assertSame(SubscriptionStatus::Trialing, $assinatura->status);
        self::assertSame(SubscriptionKind::Direct, $assinatura->kind);
    }
}
