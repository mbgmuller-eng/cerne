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

    public function test_o_teste_comeca_sem_forma_de_pagamento_e_a_tela_oferece_os_tres_meios_no_pagamento(): void
    {
        $usuario = User::factory()->create();
        $this->asaas()->shouldNotReceive('createSubscription');

        $tela = Livewire::actingAs($usuario)->test(SubscriptionIndex::class)->call('assinar', 'completo')->assertHasNoErrors();

        $assinatura = Subscription::query()->sole();
        self::assertNull($assinatura->billing_type);
        self::assertSame(SubscriptionStatus::Trialing, $assinatura->status);

        $tela->assertSee('Cartão de crédito')->assertSee('Pix Automático');
    }

    public function test_escolher_pix_automatico_no_pagamento_gera_o_qr_da_autorizacao(): void
    {
        $usuario = User::factory()->create();
        $assinatura = $this->assinatura($usuario, ['billing_type' => null]);

        $asaas = $this->asaas();
        $asaas->shouldNotReceive('createSubscription');
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_1');
        $asaas->shouldReceive('createPixAuthorization')->once()->with('cus_1', $assinatura->id, '29.90')
            ->andReturn(['id' => 'auth-1', 'status' => 'CREATED', 'payload' => 'abc', 'qrImage' => null, 'expiresAt' => null]);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->set($this->dadosFiscais())
            ->set('metodoPagamento', 'pix_automatic')
            ->call('iniciarPagamento')
            ->assertSet('pixQr.payload', 'abc');

        self::assertSame(PaymentMethod::PixAutomatic, $assinatura->fresh()->billing_type);
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

    public function test_com_a_chave_desligada_a_opcao_some_e_o_valor_e_recusado(): void
    {
        config(['billing.pix_automatic_enabled' => false]);
        $usuario = User::factory()->create();
        $this->assinatura($usuario, ['billing_type' => null]);

        Livewire::actingAs($usuario)->test(SubscriptionIndex::class)
            ->assertDontSee('Pix Automático')
            ->set($this->dadosFiscais())
            ->set('metodoPagamento', 'pix_automatic')
            ->call('iniciarPagamento')
            ->assertHasErrors('metodoPagamento');

        self::assertNull(Subscription::query()->sole()->billing_type);
    }

}
