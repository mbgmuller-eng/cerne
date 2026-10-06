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
        $inicio = strpos($html, 'class="fixed inset-x-0 bottom-0 z-30 flex border-t');
        self::assertNotFalse($inicio, 'A barra inferior do admin não apareceu.');

        return substr($html, $inicio, strpos($html, '</nav>', $inicio) - $inicio);
    }

    public function test_admin_consultor_ve_o_atalho_de_volta_em_destaque_e_a_plataforma_agrupada(): void
    {
        $admin = User::factory()->consultant()->create(['is_platform_admin' => true]);

        $lateral = $this->barraLateral($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());

        $posicao = 0;
        foreach (['Voltar para', 'Painel do consultor', 'Plataforma', 'Contas e perfis', 'Bancos', 'Minha conta'] as $texto) {
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
        foreach (['Contas', 'Bancos', 'Consultoria', 'Minha conta'] as $texto) {
            $achou = strpos($barra, $texto, $posicao);
            self::assertNotFalse($achou, "A aba '{$texto}' não aparece (ou está fora de ordem) na barra do celular.");
            $posicao = $achou;
        }
        self::assertStringContainsString('href="'.route('consultant.portfolio').'"', $barra);
    }
}
