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
            ->assertSee(Money::format(config('billing.prices.'.SubscriptionBundle::Completo->value)), false);
    }

    public function test_precos_do_usuario_final_aparecem_em_ordem_do_mais_barato_ao_mais_completo(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder([
                'Planos pra quem assina direto',
                Money::format(config('billing.prices.'.SubscriptionBundle::SaudeDocumentos->value)),
                Money::format(config('billing.prices.'.SubscriptionBundle::FinancasSegurosDocumentos->value)),
                Money::format(config('billing.prices.'.SubscriptionBundle::Completo->value)),
            ], false);
    }

    public function test_precos_de_consultor_e_corretor_ficam_numa_secao_propria(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        self::assertStringContainsString('id="planos-profissionais"', $html);

        $secao = substr($html, strpos($html, 'id="planos-profissionais"'));
        $secao = substr($secao, 0, strpos($secao, 'Perguntas frequentes'));

        foreach (['R$ 79,90', 'R$ 149,90', 'R$ 59,90', 'R$ 209,80', 'R$ 329,60', 'R$ 629,10'] as $valor) {
            self::assertStringContainsString($valor, $secao, "Faltou {$valor} na seção de profissionais");
        }

        // Preço de profissional não vaza pra seção do usuário final e vice-versa.
        $secaoUsuario = substr($html, strpos($html, 'id="planos"'), strpos($html, 'id="planos-profissionais"') - strpos($html, 'id="planos"'));
        self::assertStringNotContainsString('R$ 79,90', $secaoUsuario);
        self::assertStringNotContainsString('Quero ser consultor parceiro', $secaoUsuario);
    }

    public function test_botoes_de_cadastro_do_profissional_ja_marcam_o_papel(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('register', ['papel' => 'consultant']), false)
            ->assertSee(route('register', ['papel' => 'broker']), false);
    }

    public function test_texto_nao_promete_mais_que_assinatura_de_graca_pra_profissional(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('cobre, de graça, todos os clientes');
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
