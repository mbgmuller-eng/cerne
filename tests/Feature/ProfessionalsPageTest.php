<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfessionalsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_abre_pra_visitante(): void
    {
        $this->get(route('professionals'))
            ->assertOk()
            ->assertSee('Cerne para profissionais')
            ->assertSee('Acompanhe a carteira dos seus clientes em um só lugar.');
    }

    public function test_pagina_abre_tambem_pra_quem_esta_logado(): void
    {
        $this->actingAs(User::factory()->consultant()->create())
            ->get(route('professionals'))
            ->assertOk();
    }

    public function test_mostra_os_precos_por_quantidade_de_clientes_e_a_calculadora(): void
    {
        $html = $this->get(route('professionals'))->assertOk()->getContent();

        foreach (['R$ 79,90', 'R$ 149,90', 'R$ 59,90', 'R$ 209,80', 'R$ 329,60', 'R$ 629,10', 'Faça a sua conta'] as $texto) {
            self::assertStringContainsString($texto, $html, "Faltou {$texto}");
        }
    }

    public function test_nao_mostra_preco_do_usuario_final(): void
    {
        $this->get(route('professionals'))
            ->assertOk()
            ->assertDontSee('R$ 15,90')
            ->assertDontSee('R$ 19,90');
    }

    public function test_deixa_claro_que_profissional_de_saude_vem_em_breve(): void
    {
        $this->get(route('professionals'))
            ->assertOk()
            ->assertSee('Profissionais de saúde')
            ->assertSee('personal trainers')
            ->assertSee('nutricionistas')
            ->assertSee('Em breve');
    }

    public function test_mostra_as_telas_de_acompanhamento_da_carteira(): void
    {
        $this->get(route('professionals'))
            ->assertOk()
            ->assertSee('images/marketing/carteira-consultor.jpg', false)
            ->assertSee('images/marketing/carteira-seguros.jpg', false)
            ->assertSee('images/marketing/carteira-investimentos.jpg', false)
            ->assertSee('images/marketing/carteira-datas.jpg', false)
            ->assertSee('images/marketing/carteira-cliente.jpg', false);
    }

    public function test_botoes_levam_ao_checkout_ja_com_o_papel(): void
    {
        $this->get(route('professionals'))
            ->assertOk()
            ->assertSee(route('checkout.professional', ['papel' => 'consultant']), false)
            ->assertSee(route('checkout.professional', ['papel' => 'broker']), false);
    }

    public function test_seletor_de_publico_aparece_nas_duas_paginas(): void
    {
        foreach (['/', route('professionals')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('Para você')
                ->assertSee('Para profissionais');
        }
    }
}
