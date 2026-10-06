<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\UserRole;
use App\Livewire\Subscription\SubscriptionIndex;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Services\AsaasClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * A compra começa numa página pública com o resumo do pedido e o cadastro,
 * e termina em SubscriptionIndex, depois da confirmação do e-mail.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function dadosDeConta(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Marina Alencar',
            'email' => 'marina@exemplo.com',
            'nascimento' => '1990-05-12',
            'password' => 'Senha1234',
            'password_confirmation' => 'Senha1234',
            'termos' => '1',
            'metodo' => PaymentMethod::Pix->value,
        ], $extra);
    }

    public function test_pagina_do_plano_mostra_o_resumo_do_pedido(): void
    {
        $this->get(route('checkout.show', SubscriptionBundle::Completo->value))
            ->assertOk()
            ->assertSee('Resumo do pedido')
            ->assertSee('Completo')
            ->assertSee('R$ 29,90', false)
            ->assertSee('Hoje')
            ->assertSee('R$ 0,00', false)
            ->assertSee('7 dias grátis')
            ->assertSee(now()->addDays(7)->format('d/m/Y'))
            ->assertSee('Finanças:', false)
            ->assertSee('Saúde:', false);
    }

    public function test_cada_pacote_tem_a_sua_pagina_com_o_preco_certo(): void
    {
        foreach (SubscriptionBundle::cases() as $pacote) {
            $this->get(route('checkout.show', $pacote->value))
                ->assertOk()
                ->assertSee($pacote->label())
                ->assertSee(\App\Support\Money::format(config('billing.prices.'.$pacote->value)), false);
        }
    }

    public function test_plano_inexistente_da_404(): void
    {
        $this->get('/comprar/plano-que-nao-existe')->assertNotFound();
    }

    public function test_checkout_do_profissional_usa_o_papel_e_o_teto_da_url(): void
    {
        $this->get(route('checkout.professional', ['papel' => 'broker', 'clientes' => 30]))
            ->assertOk()
            ->assertSee('Cerne para profissionais')
            ->assertSee('Corretor de seguros')
            ->assertSee('R$ 209,80', false)
            ->assertSee('value="30"', false);
    }

    public function test_checkout_do_profissional_com_teto_invalido_cai_no_menor(): void
    {
        $this->get(route('checkout.professional', ['clientes' => 33]))
            ->assertOk()
            ->assertSee('R$ 79,90', false);
    }

    public function test_quem_ja_esta_logado_nao_ve_o_checkout(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('checkout.show', SubscriptionBundle::Completo->value))
            ->assertRedirect();
    }

    public function test_cria_conta_de_cliente_e_guarda_o_plano_escolhido(): void
    {
        Notification::fake();

        $this->post(route('checkout.store'), $this->dadosDeConta([
            'tipo' => 'usuario',
            'pacote' => SubscriptionBundle::SaudeDocumentos->value,
        ]))->assertRedirect(route('subscription.index'));

        $usuario = User::where('email', 'marina@exemplo.com')->sole();
        self::assertSame(UserRole::Client, $usuario->role);
        self::assertNull($usuario->email_verified_at);
        self::assertAuthenticatedAs($usuario);
        Notification::assertSentTo($usuario, VerifyEmailAddress::class);
        self::assertSame(
            ['tipo' => 'usuario', 'pacote' => 'saude_documentos', 'metodo' => 'pix'],
            session('checkout'),
        );
    }

    public function test_cria_conta_de_profissional_com_papel_e_teto(): void
    {
        Notification::fake();

        $this->post(route('checkout.store'), $this->dadosDeConta([
            'tipo' => 'profissional',
            'papel' => 'broker',
            'clientes' => 30,
        ]))->assertRedirect(route('subscription.index'));

        $usuario = User::where('email', 'marina@exemplo.com')->sole();
        self::assertSame(UserRole::Broker, $usuario->role);
        self::assertSame(['tipo' => 'profissional', 'clientes' => 30, 'metodo' => 'pix'], session('checkout'));
    }

    public function test_rejeita_pedido_invalido_sem_criar_conta(): void
    {
        $casos = [
            'pacote inexistente' => ['tipo' => 'usuario', 'pacote' => 'plano-falso'],
            'sem forma de pagamento' => ['tipo' => 'usuario', 'pacote' => 'completo', 'metodo' => ''],
            'forma de pagamento inválida' => ['tipo' => 'usuario', 'pacote' => 'completo', 'metodo' => 'boleto'],
            'sem aceitar os termos' => ['tipo' => 'usuario', 'pacote' => 'completo', 'termos' => ''],
            'teto fora da escala' => ['tipo' => 'profissional', 'papel' => 'consultant', 'clientes' => 25],
            'papel inválido' => ['tipo' => 'profissional', 'papel' => 'admin', 'clientes' => 10],
        ];

        foreach ($casos as $descricao => $extra) {
            $this->post(route('checkout.store'), $this->dadosDeConta($extra))->assertSessionHasErrors();
            self::assertSame(0, User::where('email', 'marina@exemplo.com')->count(), $descricao);
        }
    }

    public function test_depois_de_verificar_o_email_o_resumo_do_pedido_vem_preenchido(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->withSession(['checkout' => ['tipo' => 'usuario', 'pacote' => 'completo', 'metodo' => 'pix']]);

        Livewire::test(SubscriptionIndex::class)
            ->assertSee('Resumo do pedido')
            ->assertSee('Completo')
            ->assertSee('Confirmar e começar 7 dias grátis')
            ->assertSet('metodoPagamento', 'pix')
            ->assertSee('Escolher outro plano');
    }

    public function test_confirmar_o_pedido_cria_a_assinatura_do_plano_escolhido_e_limpa_a_sessao(): void
    {
        $usuario = User::factory()->create();

        $asaas = Mockery::mock(AsaasClient::class);
        $asaas->shouldReceive('findOrCreateCustomer')->andReturn('cus_teste');
        $asaas->shouldReceive('createSubscription')
            ->once()
            ->with('cus_teste', SubscriptionBundle::SaudeDocumentos, PaymentMethod::Pix, Mockery::type('string'), null)
            ->andReturn(['id' => 'sub_teste', 'invoiceUrl' => null]);
        $this->app->instance(AsaasClient::class, $asaas);

        $this->actingAs($usuario)->withSession(['checkout' => ['tipo' => 'usuario', 'pacote' => 'saude_documentos', 'metodo' => 'pix']]);

        Livewire::test(SubscriptionIndex::class)
            ->set('cpfCnpj', '52998224725')
            ->call('assinar', SubscriptionBundle::SaudeDocumentos->value)
            ->assertHasNoErrors();

        $assinatura = Subscription::where('user_id', $usuario->id)->sole();
        self::assertSame(SubscriptionBundle::SaudeDocumentos, $assinatura->bundle);
        self::assertSame(SubscriptionKind::Direct, $assinatura->kind);
        self::assertNull(session('checkout'));
    }

    public function test_resumo_do_profissional_usa_o_teto_escolhido(): void
    {
        $profissional = User::factory()->consultant()->create();

        $this->actingAs($profissional)->withSession(['checkout' => ['tipo' => 'profissional', 'clientes' => 30, 'metodo' => 'credit_card']]);

        Livewire::test(SubscriptionIndex::class)
            ->assertSee('Cerne para profissionais')
            ->assertSee('Até 30 clientes')
            ->assertSee('R$ 209,80', false)
            ->assertSet('clientCap', '30')
            ->assertSet('metodoPagamento', 'credit_card');
    }

    public function test_trocar_de_plano_mostra_a_lista_completa(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->withSession(['checkout' => ['tipo' => 'usuario', 'pacote' => 'completo', 'metodo' => 'pix']]);

        Livewire::test(SubscriptionIndex::class)
            ->call('trocarPlano')
            ->assertDontSee('Resumo do pedido')
            ->assertSee('Finanças + Seguros + Documentos')
            ->assertSee('Voltar ao pedido que eu escolhi')
            ->call('voltarAoPedido')
            ->assertSee('Resumo do pedido');
    }

    public function test_intencao_de_outro_tipo_de_conta_e_ignorada(): void
    {
        $profissional = User::factory()->consultant()->create();

        $this->actingAs($profissional)->withSession(['checkout' => ['tipo' => 'usuario', 'pacote' => 'completo', 'metodo' => 'pix']]);

        Livewire::test(SubscriptionIndex::class)
            ->assertDontSee('Resumo do pedido')
            ->assertSet('metodoPagamento', '');
    }

    public function test_intencao_adulterada_na_sessao_e_ignorada(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->withSession(['checkout' => ['tipo' => 'usuario', 'pacote' => 'plano-falso', 'metodo' => 'boleto']]);

        Livewire::test(SubscriptionIndex::class)
            ->assertDontSee('Resumo do pedido')
            ->assertSet('metodoPagamento', '');
    }
}
