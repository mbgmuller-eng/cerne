<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Http\Middleware\SetProfileContext;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O menu lateral do celular para o profissional (consultor e corretor): sempre existe, leva todas as telas dele, também de dentro do perfil de um cliente
 * (antes eram 10 ícones sem nome numa faixa estreita do cabeçalho, depois a aba "Carteira" da barra inferior), e o fundo escuro cobre a tela inteira.
 */
class ProfessionalMobileNavTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    private FinancialProfile $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = User::factory()->create(['name' => 'Cliente Silva']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->cliente->id, 'role' => MemberRole::Primary]);
    }

    private function vincular(User $profissional): void
    {
        ConsultantClient::factory()->create(['consultant_id' => $profissional->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
    }

    private function abrirCliente(User $profissional): self
    {
        $this->vincular($profissional);

        return $this->actingAs($profissional)->withSession([SetProfileContext::SESSION_KEY => $this->perfil->id]);
    }

    /** O trecho do atalho do profissional (recolhido) dentro do menu do cliente. */
    private function areaDoProfissional(string $html): string
    {
        $ini = strpos($html, 'data-menu-area-profissional');
        $this->assertNotFalse($ini, 'a área do profissional não está no menu');

        return substr($html, $ini, (int) strpos($html, '</nav>', $ini) - $ini);
    }

    public function test_dentro_do_cliente_o_consultor_tem_a_area_do_profissional_com_todas_as_telas_dele_agrupadas(): void
    {
        $consultor = User::factory()->consultant()->create();
        $html = $this->abrirCliente($consultor)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<aside data-menu-celular', $html);
        $area = $this->areaDoProfissional($html);

        foreach (['Consultoria', 'Seguros', 'Gestão'] as $grupo) {
            $this->assertStringContainsString($grupo, $area);
        }

        foreach (['consultant.portfolio', 'consultant.portfolio.investments', 'consultant.portfolio.debts', 'consultant.week', 'consultant.plan-templates', 'consultant.portfolio.insurance',
            'consultant.calendar', 'consultant.income', 'consultant.portfolio.important-dates', 'consultant.leads'] as $rota) {
            $this->assertStringContainsString('href="'.route($rota).'"', $area, "o menu não leva a $rota");
        }

        // A faixa de ícones sem nome do cabeçalho não existe mais: fora das barras laterais (onde cada item tem dica), nenhum desses atalhos.
        $foraDosMenus = preg_replace('/<aside.*?<\/aside>/s', '', $html);
        $this->assertStringNotContainsString('title="Investimentos da carteira"', $foraDosMenus);
        $this->assertStringNotContainsString('title="Datas importantes"', $foraDosMenus);
    }

    public function test_o_corretor_dentro_do_cliente_so_ve_o_que_e_dele_no_menu(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->abrirCliente($corretor);

        $html = $this->get(route('insurance.index'))->assertOk()->getContent();
        $area = $this->areaDoProfissional($html);

        $this->assertStringContainsString('href="'.route('consultant.portfolio.insurance').'"', $area);
        $this->assertStringContainsString('href="'.route('consultant.leads').'"', $area);
        $this->assertStringNotContainsString('href="'.route('consultant.portfolio').'"', $area, 'painel da carteira é do consultor');
    }

    public function test_cliente_comum_nao_ganha_a_area_do_profissional(): void
    {
        $html = $this->actingAs($this->cliente)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<aside data-menu-celular', $html);
        $this->assertStringNotContainsString('data-menu-area-profissional', $html);
    }

    public function test_o_consultor_sem_cliente_aberto_tem_o_menu_dele_em_toda_tela_do_app(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->vincular($consultor);

        foreach (['consultant.portfolio', 'consultant.leads', 'my-account', 'subscription.index', 'care.index'] as $rota) {
            $html = $this->actingAs($consultor)->get(route($rota))->assertOk()->getContent();

            $this->assertStringContainsString('<aside data-menu-celular', $html, "sem menu do celular em $rota");
            $this->assertStringContainsString('data-abrir-menu', $html, "sem o botão do menu em $rota");
            $this->assertStringContainsString('<span>Minha conta</span>', $html);
        }
    }

    public function test_as_linhas_de_cliente_da_carteira_empilham_no_celular_em_vez_de_alargar_a_pagina(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->vincular($consultor);

        $html = $this->actingAs($consultor)->get(route('consultant.portfolio'))->assertOk()->getContent();

        // Com nome, patrimônio e dois botões numa linha só, a linha passava de 500 px num celular de 375: a página ficava mais larga que a tela, a barra
        // fixa saía do lugar e as gavetas apareciam cortadas. No celular a linha empilha e o bloco da direita quebra; só no sm ela volta a ser uma linha.
        $this->assertStringContainsString('flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4', $html);
        $this->assertStringContainsString('flex flex-wrap items-center justify-between gap-x-4 gap-y-2 sm:shrink-0 sm:justify-end', $html);
        $this->assertStringNotContainsString('<div class="flex items-center justify-between px-5 py-3">', $html);
    }

    public function test_o_menu_do_profissional_e_do_admin_rola_quando_e_comprido(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->vincular($consultor);
        $html = $this->actingAs($consultor)->get(route('consultant.portfolio'))->getContent();
        $this->assertStringContainsString('overflow-y-auto overscroll-contain', substr($html, (int) strpos($html, '<aside data-menu-celular')));

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertStringContainsString('overflow-y-auto overscroll-contain', substr($html, (int) strpos($html, '<aside data-menu-celular')));
    }
}
