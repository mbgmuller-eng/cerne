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
            ->call('assinar', SubscriptionBundle::Completo->value);

        $assinatura = Subscription::query()->where('user_id', $profissional->id)->sole();
        self::assertSame(SubscriptionKind::Professional, $assinatura->kind);
    }

    public function test_cpf_invalido_e_rejeitado(): void
    {
        $this->fakeAsaas();
        $usuario = User::factory()->create();

        Livewire::actingAs($usuario)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '00000000000')
            ->set('metodoPagamento', PaymentMethod::Pix->value)
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertHasErrors('cpfCnpj');

        self::assertSame(0, Subscription::query()->where('user_id', $usuario->id)->count());
    }

    public function test_metodo_pagamento_vazio_e_rejeitado(): void
    {
        $this->fakeAsaas();
        $usuario = User::factory()->create();

        Livewire::actingAs($usuario)->test(\App\Livewire\Subscription\SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertHasErrors('metodoPagamento');

        self::assertSame(0, Subscription::query()->where('user_id', $usuario->id)->count());
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
