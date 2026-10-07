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
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Etapa 1: o teste grátis começa sem CPF, sem forma de pagamento e sem chamar a
 * Asaas. Etapa 2: a pessoa escolhe Pix ou cartão e só então a assinatura nasce
 * na Asaas, com vencimento no último dia do teste, e ela é levada à fatura.
 */
class TrialAndPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private function asaas(): MockInterface
    {
        $asaas = Mockery::mock(AsaasClient::class);
        $this->app->instance(AsaasClient::class, $asaas);

        return $asaas;
    }

    private function teste(User $usuario, int $diasParaTravar, array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id' => $usuario->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::SaudeDocumentos,
            'status' => SubscriptionStatus::Trialing,
            'current_period_ends_at' => Carbon::today()->addDays($diasParaTravar),
            'started_at' => now(),
        ]);
    }


    /** @return array<string, string> */
    private function dadosFiscais(): array
    {
        return [
            'fiscalNome' => 'Maria da Silva',
            'cpfCnpj' => '52998224725',
            'fiscalNascimento' => '1990-05-12',
            'cep' => '80230-010',
            'rua' => 'Avenida Sete de Setembro',
            'numero' => '2775',
            'complemento' => 'Sala 1',
            'bairro' => 'Rebouças',
            'cidade' => 'Curitiba',
            'uf' => 'PR',
        ];
    }

    // ---- etapa 1

    public function test_comecar_o_teste_nao_pede_cpf_nem_forma_de_pagamento_e_nao_chama_a_asaas(): void
    {
        $usuario = User::factory()->create();
        $asaas = $this->asaas();
        $asaas->shouldNotReceive('findOrCreateCustomer');
        $asaas->shouldNotReceive('createSubscription');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->call('assinar', SubscriptionBundle::SaudeDocumentos->value)
            ->assertHasNoErrors()
            ->assertSee('Teste grátis iniciado');

        $assinatura = Subscription::query()->sole();
        self::assertSame(SubscriptionStatus::Trialing, $assinatura->status);
        self::assertNull($assinatura->billing_type);
        self::assertNull($assinatura->asaas_subscription_id);
        self::assertSame(Carbon::today()->addDays(7)->toDateString(), $assinatura->current_period_ends_at->toDateString());
        self::assertSame(Carbon::today()->addDays(6)->toDateString(), $assinatura->trialLastDay()->toDateString());
        self::assertTrue($assinatura->isCurrent());
    }

    public function test_quem_ja_teve_teste_nao_ganha_outro_ao_cancelar_e_assinar_de_novo(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario, 3, ['status' => SubscriptionStatus::Cancelled, 'cancelled_at' => now()]);

        $this->asaas();
        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertSee('teste grátis já foi usado');

        $nova = Subscription::query()->latest('created_at')->latest('id')->get()->first(fn ($s) => $s->status === SubscriptionStatus::Trialing);
        self::assertNotNull($nova);
        self::assertFalse($nova->isCurrent(), 'a nova nasce com o teste já vencido: vai direto ao pagamento');
    }

    public function test_trocar_de_plano_durante_o_teste_muda_a_mesma_assinatura_sem_novo_teste(): void
    {
        $usuario = User::factory()->create();
        $assinatura = $this->teste($usuario, 5);
        $this->asaas();

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->call('trocarPlano')
            ->call('assinar', SubscriptionBundle::Completo->value);

        self::assertSame(1, Subscription::query()->count());
        self::assertSame(SubscriptionBundle::Completo, $assinatura->fresh()->bundle);
        self::assertSame(Carbon::today()->addDays(5)->toDateString(), $assinatura->fresh()->current_period_ends_at->toDateString(), 'os dias do teste não mudam');
    }

    public function test_profissional_precisa_escolher_o_limite_de_clientes_para_comecar(): void
    {
        $profissional = User::factory()->consultant()->create();
        $this->asaas();

        Livewire::actingAs($profissional)->test(SubscriptionIndex::class)
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertHasErrors('clientCap');

        Livewire::actingAs($profissional)->test(SubscriptionIndex::class)
            ->set('clientCap', '30')
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertHasNoErrors();

        $assinatura = Subscription::query()->sole();
        self::assertSame(30, $assinatura->client_cap);
        self::assertSame(SubscriptionKind::Professional, $assinatura->kind);
    }

    // ---- etapa 2

    public function test_pagar_cria_a_assinatura_na_asaas_com_vencimento_no_fim_do_teste_e_leva_a_fatura(): void
    {
        $usuario = User::factory()->create();
        $assinatura = $this->teste($usuario, 3);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findSubscriptionIdByReference')->with($assinatura->id)->andReturn(null);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createSubscription')->once()
            ->with('cus_1', SubscriptionBundle::SaudeDocumentos, PaymentMethod::Pix, Mockery::type('string'), null, Carbon::today()->addDays(3)->toDateString(), $assinatura->id)
            ->andReturn(['id' => 'sub_1']);
        $asaas->shouldReceive('currentInvoiceUrl')->with('sub_1')->andReturn('https://www.asaas.com/i/abc');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dadosFiscais())
            ->set('metodoPagamento', 'pix')
            ->call('iniciarPagamento')
            ->assertRedirect('https://www.asaas.com/i/abc');

        $assinatura->refresh();
        self::assertSame('sub_1', $assinatura->asaas_subscription_id);
        self::assertSame(PaymentMethod::Pix, $assinatura->billing_type);
        self::assertSame(SubscriptionStatus::Trialing, $assinatura->status, 'só vira Ativa quando a Asaas confirmar o pagamento');
        self::assertSame('52998224725', $usuario->fresh()->cpf_cnpj);
    }

    public function test_teste_ja_acabado_gera_a_cobranca_com_vencimento_hoje(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario, -2);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findSubscriptionIdByReference')->andReturn(null);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createSubscription')->once()
            ->withArgs(fn ($c, $b, $m, $d, $cap, $vencimento) => $m === PaymentMethod::CreditCard && $vencimento === Carbon::today()->toDateString())
            ->andReturn(['id' => 'sub_1']);
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn('https://www.asaas.com/i/abc');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dadosFiscais())
            ->set('metodoPagamento', 'credit_card')
            ->call('iniciarPagamento')
            ->assertRedirect('https://www.asaas.com/i/abc');
    }

    public function test_cpf_invalido_ou_forma_ausente_barram_o_pagamento_sem_chamar_a_asaas(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario, 3);
        $asaas = $this->asaas();
        $asaas->shouldNotReceive('createSubscription');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dadosFiscais())
            ->set('cpfCnpj', '11111111111')
            ->set('metodoPagamento', 'pix')
            ->call('iniciarPagamento')
            ->assertHasErrors('cpfCnpj');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dadosFiscais())
            ->set('metodoPagamento', 'boleto')
            ->call('iniciarPagamento')
            ->assertHasErrors('metodoPagamento');
    }

    public function test_forma_de_pagamento_errada_devolvida_pela_asaas_nao_deixa_cobranca_de_pe(): void
    {
        $usuario = User::factory()->create();
        $assinatura = $this->teste($usuario, 3);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findSubscriptionIdByReference')->andReturn(null);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createSubscription')->andThrow(new AsaasBillingTypeMismatch('PIX', 'BOLETO'));

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dadosFiscais())
            ->set('metodoPagamento', 'pix')
            ->call('iniciarPagamento')
            ->assertHasErrors('metodoPagamento')
            ->assertSee('Nenhuma cobrança foi gerada');

        self::assertNull($assinatura->fresh()->asaas_subscription_id);
    }

    public function test_clicar_duas_vezes_nao_cria_duas_assinaturas_na_asaas(): void
    {
        $usuario = User::factory()->create();
        $assinatura = $this->teste($usuario, 3);

        $asaas = $this->asaas();
        // A Asaas já tem uma assinatura com a referência: queda entre a criação e a gravação, ou duplo clique.
        $asaas->shouldReceive('findSubscriptionIdByReference')->with($assinatura->id)->andReturn('sub_existente');
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldNotReceive('createSubscription');
        $asaas->shouldReceive('currentInvoiceUrl')->andReturn('https://www.asaas.com/i/abc');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dadosFiscais())
            ->set('metodoPagamento', 'pix')
            ->call('iniciarPagamento');

        self::assertSame('sub_existente', $assinatura->fresh()->asaas_subscription_id);
    }

    public function test_abrir_fatura_reabre_a_cobranca_ja_gerada(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario, 3, ['billing_type' => PaymentMethod::Pix, 'asaas_subscription_id' => 'sub_1']);

        $asaas = $this->asaas();
        $asaas->shouldReceive('currentInvoiceUrl')->with('sub_1')->andReturn('https://www.asaas.com/i/abc');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->assertSee('Abrir fatura')
            ->call('abrirFatura')
            ->assertRedirect('https://www.asaas.com/i/abc');
    }

    public function test_trocar_a_forma_de_pagamento_cancela_a_fatura_antiga(): void
    {
        $usuario = User::factory()->create();
        $assinatura = $this->teste($usuario, 3, ['billing_type' => PaymentMethod::Pix, 'asaas_subscription_id' => 'sub_velha']);

        $asaas = $this->asaas();
        $asaas->shouldReceive('cancelSubscription')->once()->with('sub_velha');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)->call('trocarFormaDePagamento');

        $assinatura->refresh();
        self::assertNull($assinatura->asaas_subscription_id);
        self::assertNull($assinatura->billing_type);
    }

    public function test_tela_de_quem_esta_bloqueado_explica_e_leva_ao_pagamento(): void
    {
        $usuario = User::factory()->create();
        $this->teste($usuario, -1);
        $this->asaas();

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->assertSee('Seu acesso gratuito terminou')
            ->assertSee('Pagar para continuar')
            ->assertSee('Ir para o pagamento')
            ->assertSee('Teste grátis terminou em');
    }

    public function test_pagamento_confirmado_pela_asaas_libera_o_acesso(): void
    {
        config(['services.asaas.webhook_token' => 'segredo']);
        $usuario = User::factory()->create();
        $assinatura = $this->teste($usuario, -1, ['billing_type' => PaymentMethod::Pix, 'asaas_subscription_id' => 'sub_1']);
        self::assertFalse($assinatura->isCurrent());

        $this->postJson(route('webhooks.asaas'), [
            'id' => 'evt_1', 'event' => 'PAYMENT_RECEIVED',
            'payment' => ['id' => 'pay_1', 'subscription' => 'sub_1', 'dueDate' => Carbon::today()->toDateString()],
        ], ['asaas-access-token' => 'segredo'])->assertNoContent();

        $assinatura->refresh();
        self::assertSame(SubscriptionStatus::Active, $assinatura->status);
        self::assertTrue($assinatura->isCurrent());
        self::assertSame(Carbon::today()->addMonthNoOverflow()->toDateString(), $assinatura->current_period_ends_at->toDateString());
    }
}
