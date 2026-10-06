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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/** Tela de assinatura com Pix Automático: teste grátis local, QR de autorização e cancelamento. */
class PixAutomaticScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.pix_automatic_enabled' => true]);
    }

    private function asaas(): MockInterface
    {
        $asaas = Mockery::mock(AsaasClient::class);
        $this->app->instance(AsaasClient::class, $asaas);

        return $asaas;
    }

    private function assinatura(User $usuario, array $extra = []): Subscription
    {
        return Subscription::create($extra + [
            'user_id' => $usuario->id,
            'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo,
            'billing_type' => PaymentMethod::PixAutomatic,
            'status' => SubscriptionStatus::Trialing,
            'current_period_ends_at' => Carbon::today()->addDays(2),
            'started_at' => now(),
        ]);
    }

    public function test_assinar_com_pix_automatico_so_abre_o_teste_e_nao_chama_a_asaas(): void
    {
        $usuario = User::factory()->create();
        $this->asaas()->shouldNotReceive('createSubscription');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', 'pix_automatic')
            ->call('assinar', 'completo')
            ->assertHasNoErrors();

        $assinatura = Subscription::query()->sole();
        self::assertSame(PaymentMethod::PixAutomatic, $assinatura->billing_type);
        self::assertSame(SubscriptionStatus::Trialing, $assinatura->status);
        self::assertNull($assinatura->asaas_subscription_id);
        self::assertSame(now()->addDays(7)->toDateString(), $assinatura->current_period_ends_at->toDateString());
        self::assertTrue($assinatura->isCurrent());
    }

    public function test_tela_oferece_os_tres_meios_de_pagamento(): void
    {
        Livewire::actingAs(User::factory()->create())->test(SubscriptionIndex::class)
            ->assertSee('Cartão de crédito')
            ->assertSee('Pix Automático');
    }

    public function test_botao_de_ativar_aparece_e_gera_o_qr_com_copia_e_cola(): void
    {
        $usuario = User::factory()->create(['cpf_cnpj' => '52998224725']);
        $assinatura = $this->assinatura($usuario);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAuthorization')->once()->with('cus_1', $assinatura->id, '29.90')
            ->andReturn(['id' => 'auth-1', 'status' => 'CREATED', 'payload' => '00020101021226810014br.gov.bcb.pix', 'qrImage' => 'iVBORw0KGgo=', 'expiresAt' => '2026-10-07 15:16:21']);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->assertSee('Pagar o primeiro mês e ativar')
            ->call('ativarDebitoAutomatico')
            ->assertSee('Pix copia e cola')
            ->assertSee('00020101021226810014br.gov.bcb.pix')
            ->assertSeeHtml('data:image/png;base64,iVBORw0KGgo=')
            ->assertSee('07/10/2026')
            ->assertSee('Gerar um novo QR Code');

        self::assertSame('auth-1', $assinatura->fresh()->asaas_pix_authorization_id);
    }

    public function test_falha_da_asaas_ao_gerar_o_qr_mostra_mensagem_e_nao_quebra(): void
    {
        $usuario = User::factory()->create(['cpf_cnpj' => '52998224725']);
        $this->assinatura($usuario);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAuthorization')->andThrow(new \Illuminate\Http\Client\RequestException(new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response(500))));

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->call('ativarDebitoAutomatico')
            ->assertSet('pixQr', null);
    }

    public function test_polling_esconde_o_qr_quando_o_debito_ativa(): void
    {
        $usuario = User::factory()->create(['cpf_cnpj' => '52998224725']);
        $assinatura = $this->assinatura($usuario);

        $asaas = $this->asaas();
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAuthorization')->andReturn(['id' => 'auth-1', 'status' => 'CREATED', 'payload' => 'abc', 'qrImage' => null, 'expiresAt' => null]);

        $tela = Livewire::actingAs($usuario)->test(SubscriptionIndex::class)->call('ativarDebitoAutomatico');
        $tela->assertSet('pixQr.payload', 'abc');

        // O webhook de ativação chega entre dois polls.
        $assinatura->update(['pix_authorization_status' => 'ACTIVE']);

        $tela->call('atualizarAtivacao')
            ->assertSet('pixQr', null)
            ->assertSee('Débito automático ativo')
            ->assertDontSee('Pagar o primeiro mês e ativar');
    }

    public function test_autorizacao_recusada_mostra_aviso_e_deixa_tentar_de_novo(): void
    {
        $usuario = User::factory()->create();
        $this->assinatura($usuario, ['pix_authorization_status' => 'REFUSED', 'asaas_pix_authorization_id' => 'auth-x']);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->assertSee('A autorização anterior não foi concluída')
            ->assertSee('Pagar o primeiro mês e ativar');
    }

    public function test_quem_nao_usa_pix_automatico_nao_ve_o_bloco_de_ativacao(): void
    {
        $usuario = User::factory()->create();
        $this->assinatura($usuario, ['billing_type' => PaymentMethod::Pix, 'asaas_subscription_id' => 'sub_1']);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)->assertDontSee('Débito automático por Pix');
    }

    public function test_cancelar_cancela_a_autorizacao_e_encerra_o_acesso(): void
    {
        $usuario = User::factory()->create();
        $assinatura = $this->assinatura($usuario, ['status' => SubscriptionStatus::Active, 'pix_authorization_status' => 'ACTIVE', 'asaas_pix_authorization_id' => 'auth-1']);

        $asaas = $this->asaas();
        $asaas->shouldReceive('cancelPixAuthorization')->once()->with('auth-1');
        $asaas->shouldNotReceive('cancelSubscription');

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)->call('cancelar');

        $assinatura->refresh();
        self::assertSame(SubscriptionStatus::Cancelled, $assinatura->status);
        self::assertSame('CANCELLED', $assinatura->pix_authorization_status);
    }

    public function test_checkout_publico_aceita_pix_automatico(): void
    {
        $this->get(route('checkout.show', 'completo'))->assertOk()->assertSee('value="pix_automatic"', false)->assertSee('Pix Automático');
    }

    public function test_com_a_chave_desligada_a_opcao_some_e_o_valor_e_recusado(): void
    {
        config(['billing.pix_automatic_enabled' => false]);
        $usuario = User::factory()->create();

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->assertDontSee('Pix Automático')
            ->set('cpfCnpj', '52998224725')
            ->set('metodoPagamento', 'pix_automatic')
            ->call('assinar', 'completo')
            ->assertHasErrors('metodoPagamento');

        self::assertSame(0, Subscription::query()->count());
    }

    public function test_checkout_publico_esconde_a_opcao_com_a_chave_desligada(): void
    {
        config(['billing.pix_automatic_enabled' => false]);

        $this->get(route('checkout.show', 'completo'))->assertOk()->assertDontSee('value="pix_automatic"', false)->assertDontSee('No Pix Automático');
    }

    public function test_checkout_recusa_pix_automatico_com_a_chave_desligada(): void
    {
        config(['billing.pix_automatic_enabled' => false]);

        $this->post(route('checkout.store'), [
            'tipo' => 'usuario', 'pacote' => 'completo', 'nome' => 'Marina Alencar', 'email' => 'marina@exemplo.com',
            'nascimento' => '1990-05-12', 'password' => 'Senha1234', 'password_confirmation' => 'Senha1234',
            'termos' => '1', 'metodo' => 'pix_automatic',
        ])->assertSessionHasErrors('metodo');
    }
}
