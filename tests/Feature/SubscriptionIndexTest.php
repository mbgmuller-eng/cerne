<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AsaasClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * kind() decide Direct vs Professional sozinho, pelo papel de quem está
 * logado — não é uma escolha que aparece na tela (ver o plano da página
 * pública: antes disso, SubscriptionIndex só existia pro caminho Direct).
 */
class SubscriptionIndexTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAsaas(): void
    {
        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_teste');
        $asaas->shouldReceive('createSubscription')->andReturn(['id' => 'sub_teste', 'invoiceUrl' => 'https://sandbox.asaas.com/i/teste']);
        $this->app->instance(AsaasClient::class, $asaas);
    }

    public function test_cliente_assina_com_kind_direct(): void
    {
        $this->fakeAsaas();
        $usuario = User::factory()->create();

        Livewire::actingAs($usuario)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', PaymentMethod::Pix->value)
            ->call('assinar', SubscriptionBundle::Completo->value);

        $assinatura = Subscription::query()->where('user_id', $usuario->id)->sole();
        self::assertSame(SubscriptionKind::Direct, $assinatura->kind);
    }

    public function test_profissional_assina_com_kind_professional(): void
    {
        $this->fakeAsaas();
        $profissional = User::factory()->consultant()->create();

        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', PaymentMethod::Pix->value)
            ->set('clientCap', '10')
            ->call('assinar', SubscriptionBundle::Completo->value);

        $assinatura = Subscription::query()->where('user_id', $profissional->id)->sole();
        self::assertSame(SubscriptionKind::Professional, $assinatura->kind);
        self::assertSame(10, $assinatura->client_cap);
    }

    public function test_profissional_sem_faixa_escolhida_e_rejeitado(): void
    {
        $this->fakeAsaas();
        $profissional = User::factory()->consultant()->create();

        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', PaymentMethod::Pix->value)
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertHasErrors('clientCap');

        self::assertSame(0, Subscription::query()->where('user_id', $profissional->id)->count());
    }

    public function test_cliente_direct_nao_precisa_de_faixa(): void
    {
        $this->fakeAsaas();
        $usuario = User::factory()->create();

        Livewire::actingAs($usuario)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', PaymentMethod::Pix->value)
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertHasNoErrors();

        $assinatura = Subscription::query()->where('user_id', $usuario->id)->sole();
        self::assertNull($assinatura->client_cap);
    }

    public function test_assinar_como_profissional_ignora_o_pacote_e_cobra_pelo_teto(): void
    {
        $profissional = User::factory()->consultant()->create();

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_teste');
        $asaas->shouldReceive('createSubscription')
            ->once()
            ->with('cus_teste', SubscriptionBundle::Completo, PaymentMethod::Pix, Mockery::type('string'), 30)
            ->andReturn(['id' => 'sub_teste', 'invoiceUrl' => null]);
        $this->app->instance(AsaasClient::class, $asaas);

        // Mesmo pedindo outro pacote, profissional sempre assina o Completo.
        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', PaymentMethod::Pix->value)
            ->set('clientCap', '30')
            ->call('assinar', SubscriptionBundle::SaudeDocumentos->value);

        $assinatura = Subscription::query()->where('user_id', $profissional->id)->sole();
        self::assertSame(30, $assinatura->client_cap);
        self::assertSame(SubscriptionBundle::Completo, $assinatura->bundle);
    }

    public function test_teto_fora_da_escala_e_rejeitado(): void
    {
        $this->fakeAsaas();
        $profissional = User::factory()->consultant()->create();

        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', PaymentMethod::Pix->value)
            ->set('clientCap', '25')
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertHasErrors('clientCap');
    }

    public function test_assinatura_nasce_em_teste_gratis_com_metodo_de_pagamento_gravado(): void
    {
        $this->fakeAsaas();
        $usuario = User::factory()->create();

        Livewire::actingAs($usuario)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', PaymentMethod::Pix->value)
            ->call('assinar', SubscriptionBundle::Completo->value);

        $assinatura = Subscription::query()->where('user_id', $usuario->id)->sole();
        self::assertSame(SubscriptionStatus::Trialing, $assinatura->status);
        self::assertSame(PaymentMethod::Pix, $assinatura->billing_type);
        self::assertSame(now()->addDays(7)->toDateString(), $assinatura->current_period_ends_at->toDateString());
        self::assertTrue($assinatura->isCurrent());
    }

    public function test_assinatura_professional_ativa_nao_mostra_formulario_pro_profissional(): void
    {
        $profissional = User::factory()->consultant()->create();
        Subscription::create([
            'user_id' => $profissional->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'status' => \App\Enums\SubscriptionStatus::Active,
            'started_at' => now(),
        ]);

        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->assertDontSee('CPF ou CNPJ');
    }

    public function test_cancelar_chama_a_asaas_e_encerra_o_acesso_na_hora(): void
    {
        $usuario = User::factory()->create();
        $assinatura = Subscription::create([
            'user_id' => $usuario->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'status' => \App\Enums\SubscriptionStatus::Active,
            'asaas_subscription_id' => 'sub_cancelar_teste',
            'started_at' => now(),
        ]);

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('cancelSubscription')->once()->with('sub_cancelar_teste');
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($usuario)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->call('cancelar');

        $assinatura->refresh();
        self::assertSame(\App\Enums\SubscriptionStatus::Cancelled, $assinatura->status);
        self::assertNotNull($assinatura->cancelled_at);
        self::assertFalse($assinatura->isCurrent());
    }

    /**
     * Assinatura de cortesia (ex.: Marcelo, concedida direto no banco pra
     * cobrir os clientes vinculados sem cobrança) não tem
     * asaas_subscription_id: cancelar precisa encerrar o acesso localmente
     * sem tentar chamar uma API que não tem o que cancelar lá.
     */
    public function test_cancelar_assinatura_de_cortesia_nao_chama_a_asaas(): void
    {
        $usuario = User::factory()->create();
        $assinatura = Subscription::create([
            'user_id' => $usuario->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'status' => \App\Enums\SubscriptionStatus::Active,
            'asaas_subscription_id' => null,
            'started_at' => now(),
        ]);

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldNotReceive('cancelSubscription');
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($usuario)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->call('cancelar');

        $assinatura->refresh();
        self::assertSame(\App\Enums\SubscriptionStatus::Cancelled, $assinatura->status);
        self::assertNotNull($assinatura->cancelled_at);
    }

    public function test_aumentar_faixa_muda_o_valor_na_mesma_assinatura_sem_nova_cobranca(): void
    {
        $profissional = User::factory()->consultant()->create();
        $atual = Subscription::create([
            'user_id' => $profissional->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'client_cap' => 20,
            'billing_type' => PaymentMethod::Pix,
            'status' => SubscriptionStatus::Active,
            'asaas_subscription_id' => 'sub_atual',
            'started_at' => now(),
        ]);

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('updateSubscriptionValue')->once()->with('sub_atual', '329.60');
        $asaas->shouldNotReceive('cancelSubscription');
        $asaas->shouldNotReceive('createSubscription');
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->call('aumentarFaixa', 50);

        $atual->refresh();
        self::assertSame(50, $atual->client_cap);
        self::assertSame(SubscriptionStatus::Active, $atual->status);
        self::assertSame(1, Subscription::query()->where('user_id', $profissional->id)->count());
    }

    public function test_aumentar_faixa_fora_da_escala_e_rejeitado(): void
    {
        $profissional = User::factory()->consultant()->create();
        Subscription::create([
            'user_id' => $profissional->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'client_cap' => 10,
            'billing_type' => PaymentMethod::Pix,
            'status' => SubscriptionStatus::Active,
            'asaas_subscription_id' => 'sub_atual',
            'started_at' => now(),
        ]);

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldNotReceive('updateSubscriptionValue');
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->call('aumentarFaixa', 25)
            ->assertStatus(422);
    }

    public function test_aumentar_faixa_pra_valor_menor_ou_igual_nao_faz_nada(): void
    {
        $profissional = User::factory()->consultant()->create();
        Subscription::create([
            'user_id' => $profissional->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'client_cap' => 25,
            'billing_type' => PaymentMethod::Pix,
            'status' => SubscriptionStatus::Active,
            'asaas_subscription_id' => 'sub_atual',
            'started_at' => now(),
        ]);

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldNotReceive('cancelSubscription');
        $asaas->shouldNotReceive('createSubscription');
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->call('aumentarFaixa', 10);

        self::assertSame(1, Subscription::query()->where('user_id', $profissional->id)->count());
    }

    public function test_aumentar_faixa_sem_teto_nenhum_na_atual_e_bloqueado(): void
    {
        $profissional = User::factory()->consultant()->create();
        Subscription::create([
            'user_id' => $profissional->id,
            'kind' => SubscriptionKind::Professional,
            'bundle' => SubscriptionBundle::Completo,
            'client_cap' => null,
            'status' => SubscriptionStatus::Active,
            'started_at' => now(),
        ]);

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldNotReceive('cancelSubscription');
        $asaas->shouldNotReceive('createSubscription');
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($profissional)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->call('aumentarFaixa', 30)
            ->assertStatus(404);
    }

    public function test_cancelar_sem_assinatura_ativa_nao_chama_a_asaas(): void
    {
        $usuario = User::factory()->create();

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldNotReceive('cancelSubscription');
        $this->app->instance(AsaasClient::class, $asaas);

        Livewire::actingAs($usuario)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->call('cancelar');
    }
}
