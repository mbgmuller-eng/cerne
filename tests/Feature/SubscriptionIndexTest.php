<?php

namespace Tests\Feature;

use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
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
            ->call('assinar', SubscriptionBundle::Completo->value)
            ->assertHasErrors('cpfCnpj');

        self::assertSame(0, Subscription::query()->where('user_id', $usuario->id)->count());
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
}
