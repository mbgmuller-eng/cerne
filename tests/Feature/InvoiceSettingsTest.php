<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Subscription\SubscriptionIndex;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AsaasClient;
use App\Services\InvoiceSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Nota fiscal de serviço pela Asaas: a assinatura nasce já com a emissão
 * automática na confirmação do pagamento. Desligado por padrão e NUNCA trava o
 * pagamento.
 */
class InvoiceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function ligar(array $extra = []): void
    {
        config(['billing.invoices' => array_replace_recursive([
            'enabled' => true,
            'municipal_service_id' => null,
            'municipal_service_code' => '1.05',
            'municipal_service_name' => 'Licenciamento de programas de computação',
            'observations' => 'Assinatura do Cerne',
            'taxes' => ['retain_iss' => false, 'iss' => 2, 'cofins' => 0, 'csll' => 0, 'inss' => 0, 'ir' => 0, 'pis' => 0],
        ], $extra)]);
    }

    private function asaas(): MockInterface
    {
        $asaas = Mockery::mock(AsaasClient::class);
        $this->app->instance(AsaasClient::class, $asaas);

        return $asaas;
    }

    private function assinatura(array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id' => User::factory()->create()->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::SaudeDocumentos,
            'billing_type' => PaymentMethod::Pix,
            'status' => SubscriptionStatus::Trialing,
            'asaas_subscription_id' => 'sub_1',
            'current_period_ends_at' => Carbon::today()->addDays(3),
            'started_at' => now(),
        ]);
    }

    public function test_desligado_nao_chama_a_asaas(): void
    {
        config(['billing.invoices.enabled' => false]);
        $this->asaas()->shouldNotReceive('configureSubscriptionInvoices');

        self::assertFalse(app(InvoiceSettingsService::class)->configure($this->assinatura()));
    }

    public function test_ligado_configura_a_assinatura_e_marca_quando(): void
    {
        $this->ligar();
        $assinatura = $this->assinatura();

        $this->asaas()->shouldReceive('configureSubscriptionInvoices')->once()->with('sub_1', Mockery::on(fn ($cfg) => $cfg['municipal_service_code'] === '1.05' && $cfg['taxes']['iss'] === 2));

        self::assertTrue(app(InvoiceSettingsService::class)->configure($assinatura));
        self::assertNotNull($assinatura->fresh()->invoice_settings_at);
    }

    public function test_so_configura_uma_vez(): void
    {
        $this->ligar();
        $assinatura = $this->assinatura(['invoice_settings_at' => now()]);

        $this->asaas()->shouldNotReceive('configureSubscriptionInvoices');

        self::assertFalse(app(InvoiceSettingsService::class)->configure($assinatura));
    }

    public function test_sem_servico_ou_iss_nao_chama_a_asaas_e_avisa_no_log(): void
    {
        $this->ligar(['municipal_service_code' => null, 'taxes' => ['iss' => null]]);
        Log::spy();
        $this->asaas()->shouldNotReceive('configureSubscriptionInvoices');

        self::assertFalse(InvoiceSettingsService::isConfigured());
        self::assertFalse(app(InvoiceSettingsService::class)->configure($this->assinatura()));
        Log::shouldHaveReceived('error')->once();
    }

    public function test_servico_por_id_tambem_vale_e_iss_zero_e_valido(): void
    {
        $this->ligar(['municipal_service_id' => '477689', 'municipal_service_code' => null, 'taxes' => ['iss' => 0]]);

        self::assertTrue(InvoiceSettingsService::isConfigured());
    }

    public function test_falha_da_asaas_nao_estoura_e_deixa_pendente(): void
    {
        $this->ligar();
        Log::spy();
        $assinatura = $this->assinatura();

        $this->asaas()->shouldReceive('configureSubscriptionInvoices')->andThrow(new RuntimeException('Asaas fora do ar'));

        self::assertFalse(app(InvoiceSettingsService::class)->configure($assinatura));
        self::assertNull($assinatura->fresh()->invoice_settings_at);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_tarefa_diaria_repara_so_as_assinaturas_elegiveis(): void
    {
        $this->ligar();
        $elegivel = $this->assinatura(['asaas_subscription_id' => 'sub_ok']);
        $this->assinatura(['asaas_subscription_id' => 'sub_ja', 'invoice_settings_at' => now()]);
        $this->assinatura(['asaas_subscription_id' => null]);
        $this->assinatura(['asaas_subscription_id' => 'sub_cancelada', 'status' => SubscriptionStatus::Cancelled]);
        $this->assinatura(['asaas_subscription_id' => 'sub_pixauto', 'billing_type' => PaymentMethod::PixAutomatic]);
        $cartao = $this->assinatura(['asaas_subscription_id' => 'sub_cartao', 'billing_type' => PaymentMethod::CreditCard]);

        $asaas = $this->asaas();
        $asaas->shouldReceive('configureSubscriptionInvoices')->once()->with('sub_ok', Mockery::any());
        $asaas->shouldReceive('configureSubscriptionInvoices')->once()->with('sub_cartao', Mockery::any());

        self::assertSame(2, app(InvoiceSettingsService::class)->configurePending());
        self::assertNotNull($elegivel->fresh()->invoice_settings_at);
        self::assertNotNull($cartao->fresh()->invoice_settings_at);
    }

    public function test_tarefa_diaria_com_a_emissao_desligada_nao_faz_nada(): void
    {
        config(['billing.invoices.enabled' => false]);
        $this->assinatura();
        $this->asaas()->shouldNotReceive('configureSubscriptionInvoices');

        self::assertSame(0, app(InvoiceSettingsService::class)->configurePending());
    }

    public function test_pagar_com_a_emissao_ligada_configura_a_nota_na_assinatura_nova(): void
    {
        $this->ligar();
        $usuario = User::factory()->create();
        $assinatura = $this->assinatura(['user_id' => $usuario->id, 'asaas_subscription_id' => null, 'billing_type' => null]);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('findSubscriptionIdByReference')->andReturn(null);
        $asaas->shouldReceive('createSubscription')->andReturn(['id' => 'sub_novo']);
        $asaas->shouldReceive('configureSubscriptionInvoices')->once()->with('sub_novo', Mockery::any());
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn('https://www.asaas.com/i/abc');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set([
                'fiscalNome' => 'Maria da Silva', 'cpfCnpj' => '52998224725', 'fiscalNascimento' => '1990-05-12', 'cep' => '80230010',
                'rua' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'PR', 'metodoPagamento' => 'pix',
            ])
            ->call('iniciarPagamento')
            ->assertRedirect('https://www.asaas.com/i/abc');

        self::assertNotNull($assinatura->fresh()->invoice_settings_at);
    }

    public function test_falha_na_configuracao_da_nota_nunca_trava_o_pagamento(): void
    {
        $this->ligar();
        $usuario = User::factory()->create();
        $assinatura = $this->assinatura(['user_id' => $usuario->id, 'asaas_subscription_id' => null, 'billing_type' => null]);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('findSubscriptionIdByReference')->andReturn(null);
        $asaas->shouldReceive('createSubscription')->andReturn(['id' => 'sub_novo']);
        $asaas->shouldReceive('configureSubscriptionInvoices')->andThrow(new RuntimeException('fiscal não configurada'));
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn('https://www.asaas.com/i/abc');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set([
                'fiscalNome' => 'Maria da Silva', 'cpfCnpj' => '52998224725', 'fiscalNascimento' => '1990-05-12', 'cep' => '80230010',
                'rua' => 'Rua A', 'numero' => '1', 'bairro' => 'Centro', 'cidade' => 'Curitiba', 'uf' => 'PR', 'metodoPagamento' => 'pix',
            ])
            ->call('iniciarPagamento')
            ->assertRedirect('https://www.asaas.com/i/abc');

        self::assertSame('sub_novo', $assinatura->fresh()->asaas_subscription_id);
        self::assertNull($assinatura->fresh()->invoice_settings_at);
    }

    // ---- chamada HTTP e webhook

    public function test_chamada_a_asaas_leva_o_bloco_de_impostos_e_emite_na_confirmacao_do_pagamento(): void
    {
        config(['services.asaas.base_url' => 'https://asaas.test/v3', 'services.asaas.api_key' => 'k']);
        Http::fake(['asaas.test/v3/subscriptions/sub_1/invoiceSettings' => Http::response(['ok' => true])]);
        $this->ligar();

        app(AsaasClient::class)->configureSubscriptionInvoices('sub_1', config('billing.invoices'));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['effectiveDatePeriod'] === 'ON_PAYMENT_CONFIRMATION'
            && $r['municipalServiceCode'] === '1.05'
            && $r['taxes']['iss'] === 2.0
            && $r['taxes']['retainIss'] === false
            && array_keys($r['taxes']) === ['retainIss', 'iss', 'cofins', 'csll', 'inss', 'ir', 'pis']
            && ! array_key_exists('municipalServiceId', $r->data()));
    }

    public function test_nota_com_erro_vai_para_o_log_como_erro_e_o_webhook_responde_normalmente(): void
    {
        config(['services.asaas.webhook_token' => 'segredo']);
        Log::spy();

        $this->postJson(route('webhooks.asaas'), [
            'id' => 'evt_nf_1', 'event' => 'INVOICE_ERROR',
            'invoice' => ['id' => 'inv_1', 'payment' => 'pay_1', 'status' => 'ERROR', 'statusDescription' => 'Inscrição municipal inválida'],
        ], ['asaas-access-token' => 'segredo'])->assertNoContent();

        Log::shouldHaveReceived('error')->withArgs(fn ($mensagem, $contexto) => $mensagem === 'Asaas: problema na nota fiscal' && $contexto['descricao'] === 'Inscrição municipal inválida')->once();
    }

    public function test_nota_autorizada_so_deixa_rastro_e_nao_mexe_na_assinatura(): void
    {
        config(['services.asaas.webhook_token' => 'segredo']);
        Log::spy();
        $assinatura = $this->assinatura();

        $this->postJson(route('webhooks.asaas'), [
            'id' => 'evt_nf_2', 'event' => 'INVOICE_AUTHORIZED',
            'invoice' => ['id' => 'inv_2', 'payment' => 'pay_1', 'status' => 'AUTHORIZED'],
        ], ['asaas-access-token' => 'segredo'])->assertNoContent();

        self::assertSame(SubscriptionStatus::Trialing, $assinatura->fresh()->status);
        Log::shouldHaveReceived('info')->withArgs(fn ($mensagem) => $mensagem === 'Asaas: evento de nota fiscal')->once();
    }

    // ---- verificação de produção

    public function test_cerne_check_reprova_emissao_ligada_sem_servico_e_iss(): void
    {
        $this->ligar(['municipal_service_code' => null, 'taxes' => ['iss' => null]]);

        $this->artisan('cerne:check')->expectsOutputToContain('Nota fiscal com serviço e ISS definidos')->expectsOutputToContain('falta BILLING_NF_SERVICE_CODE')->assertSuccessful();
    }

    public function test_cerne_check_avisa_quando_a_emissao_esta_desligada(): void
    {
        config(['billing.invoices.enabled' => false]);

        $this->artisan('cerne:check')->expectsOutputToContain('Emissão de nota fiscal ligada')->expectsOutputToContain('as assinaturas pagas não geram nota fiscal')->assertSuccessful();
    }
}
