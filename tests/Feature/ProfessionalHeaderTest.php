<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Http\Middleware\SetProfileContext;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cabeçalho compacto (celular) do profissional: a barra inferior já traz todas
 * as telas, então o cabeçalho não repete atalhos nem o Sair. A exceção é
 * DENTRO do perfil de um cliente, onde ele é o único caminho de volta.
 */
class ProfessionalHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function cabecalho(string $html): string
    {
        $inicio = strpos($html, '<header');
        self::assertNotFalse($inicio, 'O cabeçalho não apareceu.');

        return substr($html, $inicio, strpos($html, '</header>', $inicio) - $inicio);
    }

    private const TELAS = [
        'consultant.portfolio', 'consultant.portfolio.investments', 'consultant.portfolio.insurance',
        'consultant.portfolio.important-dates', 'consultant.leads',
    ];

    public function test_na_area_do_consultor_o_cabecalho_nao_repete_telas_nem_tem_sair(): void
    {
        $consultor = User::factory()->consultant()->create();

        $cabecalho = $this->cabecalho($this->actingAs($consultor)->get(route('consultant.portfolio'))->assertOk()->getContent());

        foreach (self::TELAS as $rota) {
            self::assertStringNotContainsString('href="'.route($rota).'"', $cabecalho, "{$rota} está duplicada no cabeçalho.");
        }
        self::assertStringNotContainsString(route('logout'), $cabecalho);
        self::assertStringContainsString('notification', strtolower($cabecalho));
    }

    public function test_no_admin_o_cabecalho_tambem_fica_enxuto(): void
    {
        $admin = User::factory()->consultant()->create(['is_platform_admin' => true]);

        $cabecalho = $this->cabecalho($this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent());

        foreach ([...self::TELAS, 'admin.users'] as $rota) {
            self::assertStringNotContainsString('href="'.route($rota).'"', $cabecalho, "{$rota} está duplicada no cabeçalho.");
        }
        self::assertStringNotContainsString(route('logout'), $cabecalho);
    }

    public function test_minha_conta_do_profissional_tem_o_sair_no_celular(): void
    {
        $consultor = User::factory()->consultant()->create();

        $this->actingAs($consultor)->get(route('my-account'))->assertOk()
            ->assertSee('Sair da conta')
            ->assertSee(route('logout'), false);
    }

    public function test_dentro_do_perfil_do_cliente_o_consultor_mantem_os_atalhos_de_volta(): void
    {
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $html = $this->withSession([SetProfileContext::SESSION_KEY => $perfil->id])
            ->actingAs($consultor)->get(route('dashboard'))->assertOk()->getContent();
        $cabecalho = $this->cabecalho($html);

        self::assertStringContainsString('href="'.route('consultant.portfolio').'"', $cabecalho);
        self::assertStringContainsString(route('logout'), $cabecalho);
    }

    public function test_cliente_comum_continua_com_o_sair_no_cabecalho(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);

        $cabecalho = $this->cabecalho($this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent());

        self::assertStringContainsString(route('logout'), $cabecalho);
    }
}
