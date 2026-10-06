<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu do profissional em grupos: Consultoria (só consultor), Seguros,
 * Gestão (todo profissional) e Plataforma (só administrador), na barra
 * lateral e na barra inferior do celular.
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

    private function barraInferior(string $html): string
    {
        $inicio = strpos($html, 'class="relative flex border-t border-brand-950/5 bg-white/95');
        self::assertNotFalse($inicio, 'A barra inferior do profissional não apareceu.');

        return substr($html, $inicio, strpos($html, '</nav>', $inicio) - $inicio);
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

    public function test_barra_inferior_do_consultor_tem_uma_aba_por_grupo(): void
    {
        $html = $this->actingAs(User::factory()->consultant()->create())
            ->get(route('consultant.portfolio'))
            ->getContent();

        $barra = $this->barraInferior($html);

        $this->menuNaMesmaOrdem($barra, ['Consultoria', 'Seguros', 'Gestão', 'Minha conta']);
        // Grupo com duas telas abre gaveta; Seguros, com uma só, vai direto.
        self::assertStringContainsString("secao = secao === 'consultoria'", $barra);
        self::assertStringContainsString("secao = secao === 'gestao'", $barra);
        self::assertStringContainsString('href="'.route('consultant.portfolio.insurance').'"', $barra);
        self::assertStringNotContainsString('Plataforma', $barra);
    }

    public function test_so_o_administrador_ve_a_plataforma_e_ela_vem_por_ultimo(): void
    {
        $admin = User::factory()->consultant()->create(['is_platform_admin' => true]);

        $html = $this->actingAs($admin)->get(route('consultant.portfolio'))->assertOk()->getContent();

        $this->menuNaMesmaOrdem($this->barraLateral($html), ['Gestão', 'Leads', 'Plataforma', 'Painel admin']);
        $this->menuNaMesmaOrdem($this->barraInferior($html), ['Consultoria', 'Seguros', 'Gestão', 'Plataforma', 'Minha conta']);
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

        $barra = $this->barraInferior($html);
        $this->menuNaMesmaOrdem($barra, ['Seguros', 'Gestão', 'Minha conta']);
        self::assertStringNotContainsString('Consultoria', $barra);
    }

    public function test_gaveta_da_consultoria_lista_as_duas_telas(): void
    {
        $html = $this->actingAs(User::factory()->consultant()->create())
            ->get(route('consultant.portfolio'))
            ->getContent();

        $gaveta = substr($html, strpos($html, "x-show=\"secao === 'consultoria'\""));
        $gaveta = substr($gaveta, 0, strpos($gaveta, '<nav'));

        self::assertStringContainsString('href="'.route('consultant.portfolio').'"', $gaveta);
        self::assertStringContainsString('href="'.route('consultant.portfolio.investments').'"', $gaveta);
    }
}
