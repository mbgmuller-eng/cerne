<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu da área admin: telas da plataforma agrupadas e um caminho de volta
 * óbvio pra área de trabalho do administrador (que também é consultor ou
 * corretor).
 */
class AdminNavTest extends TestCase
{
    use RefreshDatabase;

    private function barraLateral(string $html): string
    {
        $inicio = strpos($html, '<aside');
        self::assertNotFalse($inicio);

        return substr($html, $inicio, strpos($html, '</aside>', $inicio) - $inicio);
    }

    private function barraInferior(string $html): string
    {
        $inicio = strpos($html, 'data-barra-admin');
        self::assertNotFalse($inicio, 'A barra inferior do admin não apareceu.');

        return substr($html, $inicio, strpos($html, '</nav>', $inicio) - $inicio);
    }

    public function test_admin_consultor_ve_o_atalho_de_volta_em_destaque_e_a_plataforma_agrupada(): void
    {
        $admin = User::factory()->consultant()->create(['is_platform_admin' => true]);

        $lateral = $this->barraLateral($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());

        $posicao = 0;
        foreach (['Voltar para', 'Painel do consultor', 'Plataforma', 'Contas e perfis', 'Cadastros', 'Bancos', 'Seguradoras', 'Exercícios', 'Minha conta'] as $texto) {
            $achou = strpos($lateral, $texto, $posicao);
            self::assertNotFalse($achou, "'{$texto}' não aparece (ou está fora de ordem) no menu do admin.");
            $posicao = $achou;
        }
        self::assertStringContainsString('href="'.route('consultant.portfolio').'"', $lateral);
        // O atalho de volta não é mais um item "Painel da carteira" perdido na lista.
        self::assertStringNotContainsString('Painel da carteira', $lateral);
    }

    public function test_admin_corretor_volta_para_o_painel_do_corretor(): void
    {
        $admin = User::factory()->broker()->create(['is_platform_admin' => true]);

        $lateral = $this->barraLateral($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());

        self::assertStringContainsString('Painel do corretor', $lateral);
        self::assertStringContainsString('href="'.route('consultant.portfolio.insurance').'"', $lateral);
    }

    public function test_admin_que_nao_e_profissional_nao_ganha_atalho_de_volta(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $lateral = $this->barraLateral($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());

        self::assertStringNotContainsString('Voltar para', $lateral);
        self::assertStringContainsString('Contas e perfis', $lateral);
    }

    public function test_barra_inferior_do_admin_tem_aba_de_volta_e_minha_conta(): void
    {
        $admin = User::factory()->consultant()->create(['is_platform_admin' => true]);

        $barra = $this->barraInferior($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());

        $posicao = 0;
        foreach (['Contas', 'Cadastros', 'Consultoria', 'Minha conta'] as $texto) {
            $achou = strpos($barra, $texto, $posicao);
            self::assertNotFalse($achou, "A aba '{$texto}' não aparece (ou está fora de ordem) na barra do celular.");
            $posicao = $achou;
        }
        self::assertStringContainsString('href="'.route('consultant.portfolio').'"', $barra);
    }

    public function test_gaveta_de_cadastros_no_celular_lista_as_tres_telas(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();

        $inicio = strpos($html, "secao === 'cadastros'");
        self::assertNotFalse($inicio, 'A gaveta de Cadastros não apareceu.');
        $gaveta = substr($html, $inicio, 2500);
        foreach (['admin.banks', 'admin.insurers', 'admin.exercises'] as $rota) {
            self::assertStringContainsString('href="'.route($rota).'"', $gaveta);
        }
    }

    public function test_contadores_de_pendencia_aparecem_nos_cadastros(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        \App\Models\GymExerciseSuggestion::record('Rosca Zottman', \App\Enums\GymMuscleGroup::Arms, \App\Enums\GymMeasureType::LoadReps);
        \App\Models\GymExerciseSuggestion::record('Pulldown Inventado', \App\Enums\GymMuscleGroup::Back, \App\Enums\GymMeasureType::LoadReps);

        $lateral = $this->barraLateral($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());

        $pos = strpos($lateral, 'Exercícios');
        self::assertNotFalse($pos);
        // Duas sugestões pendentes de exercício viram o selo "2" ao lado do item.
        self::assertMatchesRegularExpression('/Exercícios<\/span>\s*<span class="badge[^>]*>2<\/span>/u', substr($lateral, $pos - 20, 600));
    }

    public function test_quem_nao_e_admin_nao_ve_os_cadastros(): void
    {
        $this->actingAs(User::factory()->consultant()->create())->get(route('consultant.portfolio'))
            ->assertOk()->assertDontSee('Seguradoras')->assertDontSee(route('admin.exercises'), false);
    }
}
