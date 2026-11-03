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
 * A barra inferior do celular para o profissional (consultor e corretor): sempre existe, leva todas as telas dele a um toque, também de dentro do perfil de
 * um cliente (antes eram 10 ícones sem nome numa faixa estreita do cabeçalho), e o fundo da gaveta cobre a tela inteira.
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

    /** O trecho da gaveta do profissional dentro da barra do cliente. */
    private function gaveta(string $html): string
    {
        $ini = strpos($html, 'data-gaveta-profissional');
        $this->assertNotFalse($ini, 'a gaveta do profissional não está na barra');

        return substr($html, $ini, (int) strpos($html, '<nav', $ini) - $ini);
    }

    public function test_dentro_do_cliente_o_consultor_tem_a_aba_carteira_com_todas_as_telas_dele_agrupadas(): void
    {
        $consultor = User::factory()->consultant()->create();
        $html = $this->abrirCliente($consultor)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-barra="cliente"', $html);
        $gaveta = $this->gaveta($html);

        foreach (['Consultoria', 'Seguros', 'Gestão'] as $grupo) {
            $this->assertStringContainsString($grupo, $gaveta);
        }

        foreach (['consultant.portfolio', 'consultant.portfolio.investments', 'consultant.portfolio.debts', 'consultant.week', 'consultant.plan-templates', 'consultant.portfolio.insurance',
            'consultant.calendar', 'consultant.income', 'consultant.portfolio.important-dates', 'consultant.leads'] as $rota) {
            $this->assertStringContainsString('href="'.route($rota).'"', $gaveta, "a gaveta não leva a $rota");
        }

        // A aba existe na barra, ao lado de Minha conta, e a faixa de ícones sem nome do cabeçalho não existe mais.
        $this->assertStringContainsString("secao === 'profissional' ? null : 'profissional'", $html);
        $this->assertStringContainsString('<span>Carteira</span>', $html);
        $this->assertStringNotContainsString('title="Investimentos da carteira"', $html);
        $this->assertStringNotContainsString('title="Datas importantes"', $html);
    }

    public function test_o_corretor_dentro_do_cliente_so_ve_o_que_e_dele_na_gaveta(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->abrirCliente($corretor);

        $html = $this->get(route('insurance.index'))->assertOk()->getContent();
        $gaveta = $this->gaveta($html);

        $this->assertStringContainsString('href="'.route('consultant.portfolio.insurance').'"', $gaveta);
        $this->assertStringContainsString('href="'.route('consultant.leads').'"', $gaveta);
        $this->assertStringNotContainsString('href="'.route('consultant.portfolio').'"', $gaveta, 'painel da carteira é do consultor');
    }

    public function test_cliente_comum_nao_ganha_a_aba_nem_a_gaveta(): void
    {
        $html = $this->actingAs($this->cliente)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-barra="cliente"', $html);
        $this->assertStringNotContainsString('data-gaveta-profissional', $html);
        $this->assertStringNotContainsString('<span>Carteira</span>', $html);
    }

    public function test_o_consultor_sem_cliente_aberto_tem_a_barra_dele_em_toda_tela_do_app(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->vincular($consultor);

        foreach (['consultant.portfolio', 'consultant.leads', 'my-account', 'subscription.index', 'care.index'] as $rota) {
            $html = $this->actingAs($consultor)->get(route($rota))->assertOk()->getContent();

            $this->assertStringContainsString('data-barra="profissional"', $html, "sem barra inferior em $rota");
            $this->assertStringContainsString('<span>Minha conta</span>', $html);
        }
    }

    public function test_o_fundo_da_gaveta_fica_fora_do_conteiner_transformado_e_existe_um_so(): void
    {
        $consultor = User::factory()->consultant()->create();
        $html = $this->abrirCliente($consultor)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'bg-brand-950/30'), 'um fundo só');
        $this->assertLessThan(strpos($html, 'data-barra="cliente"'), strpos($html, 'bg-brand-950/30'), 'o fundo vem antes do contêiner com transform, não dentro dele');
    }

    public function test_as_gavetas_do_profissional_e_do_admin_rolam_quando_passam_de_60_por_cento_da_tela(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->vincular($consultor);
        $html = $this->actingAs($consultor)->get(route('consultant.portfolio'))->getContent();
        $this->assertStringContainsString('max-h-[60vh] overflow-y-auto', substr($html, (int) strpos($html, 'data-barra="profissional"')));

        $admin = User::factory()->create(['is_platform_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertStringContainsString('max-h-[60vh] overflow-y-auto', substr($html, (int) strpos($html, 'data-barra="admin"')));
    }
}
