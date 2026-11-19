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

    public function test_logado_continua_vendo_o_site_e_ganha_o_atalho_para_o_painel(): void
    {
        // A raiz é sempre o site: antes, quem estava logado era jogado no painel e não conseguia mais ver a vitrine.
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Ir para o painel')
            ->assertSeeHtml('data-ir-ao-painel')
            ->assertSee(route('dashboard'), false)
            ->assertDontSee('Minha conta');
    }

    public function test_visitante_continua_vendo_minha_conta_que_leva_ao_login(): void
    {
        $this->get('/')->assertOk()->assertSee('Minha conta')->assertSee(route('login'), false)->assertDontSee('Ir para o painel');
    }

    public function test_dentro_do_app_ha_um_link_para_o_site(): void
    {
        $usuario = User::factory()->create();
        $perfil = \App\Models\FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        \App\Models\ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => \App\Enums\MemberRole::Primary]);

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->assertSeeHtml('data-site-do-cerne')->assertSee(route('home'), false);
    }

    public function test_entrar_e_a_pagina_de_login_de_quem_nao_esta_logado(): void
    {
        $this->get('/entrar')->assertOk()->assertSee('Entrar');
    }

    public function test_termos_abre_pra_qualquer_um(): void
    {
        $this->get(route('legal.terms'))->assertOk();
    }
}
