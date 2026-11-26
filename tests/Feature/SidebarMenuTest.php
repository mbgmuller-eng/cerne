<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu lateral do desktop: o grupo Finanças junta os cadastros e regras no fim, e as três barras (cliente, consultor, administrador)
 * ganham o botão que recolhe o menu numa trilha só de ícones (a classe `menu-recolhido` e o estilo moram em resources/css/app.css).
 */
class SidebarMenuTest extends TestCase
{
    use RefreshDatabase;

    /** Só a barra lateral: o HTML também traz a barra inferior do celular. */
    private function barraLateral(string $html): string
    {
        $inicio = strpos($html, '<aside');
        self::assertNotFalse($inicio, 'A barra lateral não apareceu.');

        return substr($html, $inicio, strpos($html, '</aside>', $inicio) - $inicio);
    }

    private function cliente(): User
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return $usuario;
    }

    public function test_cadastros_e_regras_ficam_juntos_no_fim_das_financas(): void
    {
        $this->cliente();

        $lateral = $this->barraLateral($this->get(route('dashboard'))->assertOk()->getContent());

        $posicao = 0;
        foreach (['Visão geral', 'Fluxo de caixa', 'Contas fixas', 'Dívidas', 'Investimentos', 'Objetivos', 'Importar', 'Cadastros e regras', 'Contas &amp; Cartões', 'Regras de categorização', 'Regra de orçamento', 'Seguros'] as $texto) {
            $achou = strpos($lateral, $texto, $posicao);
            self::assertNotFalse($achou, "'{$texto}' não aparece (ou está fora de ordem) no menu.");
            $posicao = $achou;
        }

        // O subtítulo só aparece uma vez e nenhum cadastro ficou solto no meio do grupo.
        self::assertSame(1, substr_count($lateral, 'Cadastros e regras'));
        $subtitulo = strpos($lateral, 'Cadastros e regras');
        foreach (['Fluxo de caixa', 'Contas fixas', 'Dívidas', 'Investimentos', 'Objetivos', 'Importar'] as $antes) {
            self::assertLessThan($subtitulo, strpos($lateral, $antes), "{$antes} devia vir antes dos cadastros.");
        }
    }

    public function test_a_barra_do_cliente_tem_o_botao_de_recolher_e_dica_em_cada_item(): void
    {
        $this->cliente();

        $lateral = $this->barraLateral($this->get(route('dashboard'))->assertOk()->getContent());

        self::assertStringContainsString('data-lateral', $lateral);
        self::assertStringContainsString('data-alternar-menu', $lateral);
        // Recolhido só aparece o ícone: a dica (title) diz o que é cada um.
        self::assertStringContainsString('title="Fluxo de caixa"', $lateral);
        self::assertStringContainsString('title="Minha conta"', $lateral);
        // Os rótulos ganham a classe que o modo recolhido esconde.
        self::assertStringContainsString('lateral-texto', $lateral);
    }

    public function test_cada_area_do_menu_abre_e_fecha_e_a_da_tela_atual_vem_marcada(): void
    {
        $this->cliente();

        $html = $this->get(route('debts.index'))->assertOk()->getContent();
        $lateral = $this->barraLateral($html);

        foreach (['perfil-financas', 'perfil-seguros', 'perfil-documentos', 'perfil-saude'] as $chave) {
            self::assertSame(1, substr_count($lateral, 'data-alternar-secao="'.$chave.'"'), $chave);
            self::assertStringContainsString('data-secao="'.$chave.'"', $lateral);
        }
        self::assertSame(4, substr_count($lateral, 'data-secao-itens'));
        // Só a área da tela de agora é marcada como ativa.
        self::assertMatchesRegularExpression('/data-secao="perfil-financas"\s+data-ativa/', $lateral);
        self::assertSame(1, preg_match_all('/data-secao="[a-z-]+"\s+data-ativa/', $lateral));

        // As escolhas guardadas valem antes do primeiro desenho, sabendo qual é a área da tela.
        $script = strpos($html, 'cerne.menu.secoes');
        self::assertNotFalse($script);
        self::assertLessThan(strpos($html, '<body'), $script);
        self::assertStringContainsString('window.cerneSecoes.estilo(window.cerneSecoes.ler(), "perfil-financas")', $html);
    }

    public function test_telas_de_dentro_marcam_a_area_certa_e_documentos_nao_se_confunde_com_importar(): void
    {
        $this->cliente();

        foreach ([
            'health.exams.create' => 'perfil-saude',
            'health.appointments.index' => 'perfil-saude',
            'documents.vault.index' => 'perfil-documentos',
            'documents.index' => 'perfil-financas',
            'insurance.index' => 'perfil-seguros',
        ] as $rota => $esperada) {
            $html = $this->get(route($rota))->assertOk()->getContent();
            self::assertStringContainsString('window.cerneSecoes.ler(), "'.$esperada.'")', $html, $rota);
        }

        // Minha conta não é de área nenhuma: nada é reaberto.
        self::assertStringContainsString('window.cerneSecoes.ler(), null)', $this->get(route('my-account'))->assertOk()->getContent());
    }

    public function test_consultor_e_administrador_tambem_abrem_e_fecham_as_areas(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lateral = $this->barraLateral($this->actingAs($consultor)->get(route('consultant.portfolio'))->assertOk()->getContent());
        foreach (['pro-consultoria', 'pro-seguros', 'pro-gestao'] as $chave) {
            self::assertStringContainsString('data-alternar-secao="'.$chave.'"', $lateral);
        }
        self::assertMatchesRegularExpression('/data-secao="pro-consultoria"\s+data-ativa/', $lateral);

        $admin = User::factory()->consultant()->create(['is_platform_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.banks'))->assertOk()->getContent();
        $lateral = $this->barraLateral($html);
        self::assertStringContainsString('data-alternar-secao="admin-plataforma"', $lateral);
        self::assertMatchesRegularExpression('/data-secao="admin-cadastros"\s+data-ativa/', $lateral);
    }

    public function test_o_estado_recolhido_e_aplicado_antes_do_primeiro_desenho(): void
    {
        $this->cliente();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $script = strpos($html, "localStorage.getItem('cerne.menu') === 'recolhido'");
        self::assertNotFalse($script, 'O script que aplica o menu recolhido sumiu do <head>.');
        // Vem antes dos estilos e do conteúdo: sem isso a barra piscava larga e encolhia.
        self::assertLessThan(strpos($html, '<body'), $script);
    }

    public function test_consultor_e_administrador_tambem_podem_recolher_o_menu(): void
    {
        $consultor = User::factory()->consultant()->create();
        $lateral = $this->barraLateral($this->actingAs($consultor)->get(route('consultant.portfolio'))->assertOk()->getContent());
        self::assertStringContainsString('data-alternar-menu', $lateral);
        self::assertStringContainsString('title="Painel da carteira"', $lateral);

        $admin = User::factory()->consultant()->create(['is_platform_admin' => true]);
        $lateral = $this->barraLateral($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());
        self::assertStringContainsString('data-alternar-menu', $lateral);
        self::assertStringContainsString('title="Contas e perfis"', $lateral);
    }
}
