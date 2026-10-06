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
 * Barra inferior do celular: uma aba por categoria + "Minha conta".
 * Categoria com várias telas abre uma gaveta (botão); com uma tela só vai
 * direto (link).
 */
class BottomNavTest extends TestCase
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

    /** Só a barra inferior: o HTML também traz a barra lateral do desktop. */
    private function barraInferior(string $html): string
    {
        $inicio = strpos($html, 'class="relative flex border-t border-white/10');
        self::assertNotFalse($inicio, 'A barra inferior não apareceu na página.');

        return substr($html, $inicio, strpos($html, '</nav>', $inicio) - $inicio);
    }

    public function test_cliente_tem_uma_aba_por_categoria_e_minha_conta(): void
    {
        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->perfil->members()->first());

        $barra = $this->barraInferior($this->get(route('dashboard'))->assertOk()->getContent());

        foreach (['Finanças', 'Seguros', 'Documentos', 'Saúde', 'Minha conta'] as $aba) {
            self::assertStringContainsString($aba, $barra, "Faltou a aba {$aba}");
        }

        // Várias telas = gaveta; uma tela só = link direto.
        self::assertStringContainsString("secao = secao === 'financas'", $barra);
        self::assertStringContainsString("secao = secao === 'saude'", $barra);
        self::assertStringContainsString('href="'.route('insurance.index').'"', $barra);
        self::assertStringContainsString('href="'.route('documents.vault.index').'"', $barra);
        self::assertStringContainsString('href="'.route('my-account').'"', $barra);
        self::assertStringNotContainsString('Mais', $barra);
    }

    public function test_gaveta_de_financas_lista_as_telas_da_categoria(): void
    {
        $this->actingAs($this->cliente);
        app(ProfileContext::class)->set($this->perfil, $this->perfil->members()->first());

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $gaveta = substr($html, strpos($html, "x-show=\"secao === 'financas'\""));
        $gaveta = substr($gaveta, 0, strpos($gaveta, '<nav class="relative flex'));

        foreach (['cashflow.index', 'fixedbills.index', 'accounts.index', 'investments.index', 'goals.index'] as $rota) {
            self::assertStringContainsString('href="'.route($rota).'"', $gaveta, "Faltou {$rota} na gaveta de Finanças");
        }
    }

    public function test_consultor_com_cliente_aberto_nao_ganha_a_aba_de_saude(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $this->cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $html = $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $barra = $this->barraInferior($html);

        foreach (['Finanças', 'Seguros', 'Documentos', 'Minha conta'] as $aba) {
            self::assertStringContainsString($aba, $barra);
        }
        self::assertStringNotContainsString('Saúde', $barra);
    }

    public function test_corretor_com_cliente_aberto_so_tem_seguros_e_minha_conta(): void
    {
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id,
            'client_id' => $this->cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $barra = $this->barraInferior(
            $this->actingAs($corretor)
                ->withSession(['cerne.active_profile_id' => $this->perfil->id])
                ->get(route('insurance.index'))
                ->assertOk()
                ->getContent()
        );

        self::assertStringContainsString('Seguros', $barra);
        self::assertStringContainsString('Minha conta', $barra);
        foreach (['Finanças', 'Documentos', 'Saúde'] as $aba) {
            self::assertStringNotContainsString($aba, $barra, "Corretor não deveria ver {$aba}");
        }
    }
}
