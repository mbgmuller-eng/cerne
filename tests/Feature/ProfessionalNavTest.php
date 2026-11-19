<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu do profissional em grupos: Consultoria (só consultor), Seguros,
 * Gestão (todo profissional) e Plataforma (só administrador), na barra
 * lateral (desktop) e no menu lateral do celular.
 */
class ProfessionalNavTest extends TestCase
{
    use RefreshDatabase;

    private function barraLateral(string $html): string
    {
        $inicio = strpos($html, '<aside');
        self::assertNotFalse($inicio);

        return substr($html, $inicio, strpos($html, '</aside>', $inicio) - $inicio);
    }

    private function menuNaMesmaOrdem(string $html, array $textos): void
    {
        $posicao = 0;
        foreach ($textos as $texto) {
            $achou = strpos($html, $texto, $posicao);
            self::assertNotFalse($achou, "'{$texto}' não aparece (ou está fora de ordem) no menu.");
            $posicao = $achou;
        }
    }

    /** O menu lateral do celular (que desliza da esquerda). */
    private function menuCelular(string $html): string
    {
        $inicio = strpos($html, '<aside data-menu-celular');
        self::assertNotFalse($inicio, 'O menu do celular não apareceu.');

        return substr($html, $inicio, strpos($html, '</aside>', $inicio) - $inicio);
    }

    public function test_consultor_ve_os_grupos_na_ordem_e_sem_a_plataforma(): void
    {
        $html = $this->actingAs(User::factory()->consultant()->create())
            ->get(route('consultant.portfolio'))
            ->assertOk()
            ->getContent();

        $lateral = $this->barraLateral($html);

        $this->menuNaMesmaOrdem($lateral, [
            'Consultoria', 'Painel da carteira', 'Investimentos da carteira',
            'Seguros', 'Seguros da carteira',
            'Gestão', 'Datas importantes', 'Leads',
        ]);
        self::assertStringNotContainsString('Plataforma', $lateral);
        self::assertStringNotContainsString('Painel admin', $lateral);
    }

    public function test_menu_do_celular_do_consultor_tem_um_grupo_por_secao(): void
    {
        $html = $this->actingAs(User::factory()->consultant()->create())
            ->get(route('consultant.portfolio'))
            ->getContent();

        $menu = $this->menuCelular($html);

        $this->menuNaMesmaOrdem($menu, ['Consultoria', 'Seguros', 'Gestão', 'Minha conta']);
        self::assertStringContainsString('href="'.route('consultant.portfolio.insurance').'"', $menu);
        self::assertStringNotContainsString('Plataforma', $menu);
        // Já está na área do profissional: o atalho de volta seria um link para a própria tela.
        self::assertStringNotContainsString('Voltar para', $menu);
    }

    public function test_so_o_administrador_ve_a_plataforma_e_ela_vem_por_ultimo(): void
    {
        $admin = User::factory()->consultant()->create(['is_platform_admin' => true]);

        $html = $this->actingAs($admin)->get(route('consultant.portfolio'))->assertOk()->getContent();

        $this->menuNaMesmaOrdem($this->barraLateral($html), ['Gestão', 'Leads', 'Plataforma', 'Painel admin']);
        $this->menuNaMesmaOrdem($this->menuCelular($html), ['Consultoria', 'Seguros', 'Gestão', 'Plataforma', 'Painel admin', 'Minha conta']);
    }

    public function test_corretor_nao_tem_consultoria_so_seguros_e_gestao(): void
    {
        $html = $this->actingAs(User::factory()->broker()->create())
            ->get(route('consultant.portfolio.insurance'))
            ->assertOk()
            ->getContent();

        $lateral = $this->barraLateral($html);

        $this->menuNaMesmaOrdem($lateral, ['Seguros', 'Seguros da carteira', 'Gestão', 'Datas importantes', 'Leads']);
        self::assertStringNotContainsString('Consultoria', $lateral);
        self::assertStringNotContainsString('Painel da carteira', $lateral);
        self::assertStringNotContainsString('Investimentos da carteira', $lateral);
        self::assertStringNotContainsString('Painel admin', $lateral);

        $menu = $this->menuCelular($html);
        $this->menuNaMesmaOrdem($menu, ['Seguros', 'Gestão', 'Minha conta']);
        self::assertStringNotContainsString('Consultoria', $menu);
    }

    public function test_menu_do_celular_lista_as_telas_da_consultoria(): void
    {
        $html = $this->actingAs(User::factory()->consultant()->create())
            ->get(route('consultant.portfolio'))
            ->getContent();

        $menu = $this->menuCelular($html);

        self::assertStringContainsString('href="'.route('consultant.portfolio').'"', $menu);
        self::assertStringContainsString('href="'.route('consultant.portfolio.investments').'"', $menu);
    }
}
