<?php

namespace Tests\Feature;

use App\Enums\SubscriptionBundle;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_ve_a_vitrine(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Assinar')
            // O preço é desenhado em partes (R$, reais grandes, centavos), então
            // checa as partes em ordem em vez do texto corrido.
            ->assertSeeInOrder(['R$', '>29<', ',90'], false);
    }

    public function test_vitrine_tem_a_aba_de_investimentos(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Investimentos')
            ->assertSee('images/marketing/investimentos.jpg', false);
    }

    public function test_precos_do_usuario_final_aparecem_em_ordem_do_mais_barato_ao_mais_completo(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder([
                'Escolha o seu plano',
                '>15<',
                '>19<',
                '>29<',
            ], false);
    }

    public function test_plano_completo_tem_destaque_e_cada_plano_leva_ao_checkout(): void
    {
        $resposta = $this->get('/')->assertOk()->assertSee('Mais completo');

        foreach (SubscriptionBundle::cases() as $pacote) {
            $resposta->assertSee(route('checkout.show', $pacote->value), false);
        }
    }

    public function test_cada_plano_lista_o_que_inclui(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('7 dias grátis, sem cobrança hoje')
            ->assertSee('Ficha de saúde', false);
    }

    public function test_landing_e_so_do_usuario_e_aponta_pra_pagina_de_profissionais(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('professionals'), false)
            ->assertDontSee('Quero ser consultor parceiro')
            ->assertDontSee('Faça a sua conta')
            ->assertDontSee('R$ 79,90');
    }

    public function test_titulo_usa_em_um_so_lugar_sem_o_tom_coloquial(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('tudo o que sustenta sua vida, em um só lugar.', false)
            ->assertDontSee('num só lugar');
    }

    public function test_logado_e_redirecionado_pro_painel(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_termos_abre_pra_qualquer_um(): void
    {
        $this->get(route('legal.terms'))->assertOk();
    }
}
