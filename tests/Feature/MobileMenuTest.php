<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menu lateral do celular: desliza da esquerda no lugar da barra inferior (que tinha uma gaveta por categoria). São as MESMAS telas da barra
 * lateral do desktop, em grupos; abre pelo botão do cabeçalho e leva também a conta, o tema e o Sair.
 */
class MobileMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    private FinancialProfile $perfil;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cliente = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id]);
        ProfileMember::factory()->create([
            'profile_id' => $this->perfil->id,
            'user_id' => $this->cliente->id,
            'role' => MemberRole::Primary,
        ]);
    }

    /** Só o menu do celular: o HTML também traz a barra lateral do desktop. */
    private function menu(string $html): string
    {
        $inicio = strpos($html, '<aside data-menu-celular');
        self::assertNotFalse($inicio, 'O menu do celular não apareceu na página.');

        return substr($html, $inicio, strpos($html, '</aside>', $inicio) - $inicio);
    }

    private function comoCliente(): void
    {
        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->perfil->members()->first());
    }

    public function test_nao_existe_mais_barra_inferior_e_o_cabecalho_tem_o_botao_do_menu(): void
    {
        $this->comoCliente();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        self::assertStringNotContainsString('data-barra', $html);
        self::assertStringNotContainsString('tab-item', $html);
        self::assertStringContainsString('data-abrir-menu', $html);
        self::assertStringContainsString('aria-label="Abrir o menu"', $html);
    }

    public function test_cliente_ve_todos_os_grupos_na_mesma_ordem_da_barra_lateral(): void
    {
        $this->comoCliente();

        $menu = $this->menu($this->get(route('dashboard'))->assertOk()->getContent());

        $posicao = 0;
        foreach (['Finanças', 'Visão geral', 'Fluxo de caixa', 'Contas fixas', 'Dívidas', 'Investimentos', 'Objetivos', 'Importar', 'Cadastros e regras', 'Contas &amp; Cartões', 'Regras de categorização', 'Regra de orçamento',
            'Seguros', 'Apólices', 'Documentos', 'Meus documentos', 'Saúde', 'Atividades', 'Ficha de Saúde', 'Agenda de Saúde', 'Cuidados e itens', 'Minha conta', 'Sair'] as $texto) {
            $achou = strpos($menu, $texto, $posicao);
            self::assertNotFalse($achou, "'{$texto}' não aparece (ou está fora de ordem) no menu do celular.");
            $posicao = $achou;
        }

        foreach (['cashflow.index', 'fixedbills.index', 'accounts.index', 'categorization-rules.index', 'budget.index', 'investments.index', 'goals.index', 'insurance.index', 'documents.vault.index', 'my-account'] as $rota) {
            self::assertStringContainsString('href="'.route($rota).'"', $menu, "Faltou {$rota} no menu do celular.");
        }
    }

    public function test_o_menu_leva_o_sair_e_o_tema_que_sairam_do_cabecalho(): void
    {
        $this->comoCliente();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $menu = $this->menu($html);

        self::assertStringContainsString(route('logout'), $menu);
        self::assertStringContainsString('data-theme-switcher', $menu);

        // O cabeçalho do celular, onde há menu lateral, fica só com o essencial (ajuda e sino).
        $cabecalho = substr($html, strpos($html, '<header'), strpos($html, '</header>') - strpos($html, '<header'));
        self::assertStringNotContainsString(route('logout'), $cabecalho);
        self::assertStringNotContainsString('data-theme-switcher', $cabecalho);
    }

    public function test_o_menu_fecha_de_todos_os_jeitos_e_rola_quando_e_comprido(): void
    {
        $this->comoCliente();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $menu = $this->menu($html);

        self::assertStringContainsString('@keydown.escape.window="menu = false"', $menu);
        self::assertStringContainsString('aria-label="Fechar o menu"', $menu);
        self::assertStringContainsString('inicioX', $menu); // arrastar para a esquerda
        self::assertStringContainsString('overflow-y-auto', $menu);
        self::assertStringContainsString('role="dialog"', $menu);

        // O fundo escuro cobre a tela toda e fica FORA da gaveta (um só, antes dela): tocar nele fecha.
        self::assertSame(1, substr_count($html, 'bg-brand-950/50'));
        self::assertLessThan(strpos($html, '<aside data-menu-celular'), strpos($html, 'bg-brand-950/50'));
        // A página por baixo não rola enquanto o menu está aberto, e o menu some se a tela virar larga.
        self::assertStringContainsString("classList.toggle('overflow-hidden', menu)", $html);
        self::assertStringContainsString('window.innerWidth >= 1024', $html);
    }

    public function test_o_botao_de_lancar_foi_para_o_canto_e_some_com_o_menu_aberto(): void
    {
        $this->comoCliente();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        self::assertStringContainsString('bottom-[calc(1.25rem+env(safe-area-inset-bottom))]', $html);
        self::assertStringNotContainsString('5.5rem', $html);
        self::assertStringContainsString('x-show="! menu"', $html);
    }

    public function test_cliente_comum_nao_ganha_a_area_do_profissional(): void
    {
        $this->comoCliente();

        $menu = $this->menu($this->get(route('dashboard'))->assertOk()->getContent());

        self::assertStringNotContainsString('data-menu-area-profissional', $menu);
        self::assertStringNotContainsString('Área do profissional', $menu);
        self::assertStringNotContainsString('Voltar para', $menu);
    }

    public function test_consultor_com_cliente_aberto_nao_ganha_saude_e_tem_a_area_do_profissional(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $this->cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $menu = $this->menu($this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent());

        foreach (['Finanças', 'Seguros', 'Documentos', 'Minha conta', 'Área do profissional', 'Voltar para'] as $texto) {
            self::assertStringContainsString($texto, $menu);
        }
        self::assertStringNotContainsString('Ficha de Saúde', $menu);
        self::assertStringNotContainsString('Agenda de Saúde', $menu);
        // O caminho de volta para a carteira, e todas as telas dele, ficam ali (recolhidas).
        self::assertStringContainsString('href="'.route('consultant.portfolio').'"', $menu);
    }

    public function test_corretor_com_cliente_aberto_so_tem_seguros_e_minha_conta(): void
    {
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id,
            'client_id' => $this->cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $menu = $this->menu(
            $this->actingAs($corretor)
                ->withSession(['cerne.active_profile_id' => $this->perfil->id])
                ->get(route('insurance.index'))
                ->assertOk()
                ->getContent()
        );

        self::assertStringContainsString('Seguros', $menu);
        self::assertStringContainsString('Minha conta', $menu);
        foreach (['Finanças', 'Fluxo de caixa', 'Ficha de Saúde'] as $texto) {
            self::assertStringNotContainsString($texto, $menu, "Corretor não deveria ver {$texto}");
        }
    }

    public function test_o_administrador_ve_o_selo_de_erros_abertos_no_menu(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        // Nos outros testes o acompanhamento fica desligado (phpunit.xml): liga só aqui e anota um erro pelo próprio registrador do app.
        config(['cerne.error_tracking.enabled' => true, 'cerne.error_tracking.report_to' => []]);
        app(\App\Services\ErrorTracking\ErrorRecorder::class)->record(new \RuntimeException('erro de teste do menu'));

        $menu = $this->menu($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());

        self::assertMatchesRegularExpression('/Erros<\/span>\s*<span class="badge[^>]*>1<\/span>/u', $menu);
    }
}
