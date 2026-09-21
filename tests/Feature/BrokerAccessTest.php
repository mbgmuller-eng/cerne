<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\CashFlow\CashFlowIndex;
use App\Livewire\Consultant\LeadsIndex;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Livewire\Dashboard;
use App\Livewire\FixedBills\FixedBillsIndex;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\ConsultantClient;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Corretor de seguros: um profissional de fora que só enxerga Seguros — e,
 * dentro de Seguros, só o que tem o próprio broker_id (ver
 * InsurancePolicyBrokerScope). Diferente do consultor financeiro, que
 * continua vendo tudo, sempre.
 */
class BrokerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_corretor_sem_vinculo_nao_pode_abrir_o_perfil_do_cliente(): void
    {
        $corretor = User::factory()->broker()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);

        self::assertFalse($corretor->can('view', $perfil));
    }

    public function test_corretor_com_vinculo_ativo_pode_abrir_o_perfil_do_cliente(): void
    {
        $corretor = User::factory()->broker()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        self::assertTrue($corretor->can('view', $perfil));
    }

    public function test_corretor_com_vinculo_pendente_nao_pode_abrir_o_perfil(): void
    {
        $corretor = User::factory()->broker()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $corretor->id, 'client_id' => $cliente->id,
        ]);

        self::assertFalse($corretor->can('view', $perfil));
    }

    /**
     * Regressão do ajuste em SetProfileContext: antes, só isConsultant()
     * virava "alguém de fora olhando" — um corretor seria tratado como se
     * fosse o cônjuge pela privacidade do casal, em vez de um profissional
     * de fora. Mesmo teste de MemberPrivacyTest::
     * test_consultor_vinculado_ve_tudo_independente_da_privacidade(), só
     * que com um corretor.
     */
    public function test_corretor_vinculado_nao_e_tratado_como_conjuge_pela_privacidade_do_casal(): void
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);
        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);

        $oculto = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'member_id' => $membroConjuge->id,
            'is_private' => true,
        ]);

        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        self::assertTrue(ExpenseRecord::all()->contains($oculto));
    }

    public function test_corretor_e_bloqueado_das_telas_financeiras(): void
    {
        [$corretor, $perfil] = $this->criarCorretorVinculado();
        $this->actingAs($corretor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(CashFlowIndex::class)->assertStatus(403);
    }

    public function test_corretor_e_bloqueado_de_contas_fixas(): void
    {
        [$corretor, $perfil] = $this->criarCorretorVinculado();
        $this->actingAs($corretor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(FixedBillsIndex::class)->assertStatus(403);
    }

    public function test_corretor_e_bloqueado_da_visao_geral(): void
    {
        [$corretor, $perfil] = $this->criarCorretorVinculado();
        $this->actingAs($corretor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(Dashboard::class)->assertStatus(403);
    }

    public function test_corretor_consegue_abrir_seguros_do_cliente_vinculado(): void
    {
        [$corretor, $perfil] = $this->criarCorretorVinculado();
        $this->actingAs($corretor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(InsuranceIndex::class)->assertStatus(200);
    }

    public function test_corretor_pode_abrir_leads_e_seguros_da_carteira(): void
    {
        $corretor = User::factory()->broker()->create();
        $this->actingAs($corretor);

        Livewire::test(LeadsIndex::class)->assertStatus(200);
        Livewire::test(PortfolioInsurance::class)->assertStatus(200);
    }

    public function test_consultor_financeiro_continua_com_acesso_total_as_telas_financeiras(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(CashFlowIndex::class)->assertStatus(200);
    }

    /**
     * "Abrir perfil" sempre redirecionava pra Visão geral — tela que agora
     * bloqueia corretor (ver Dashboard::mount()). Sem esse ajuste, o
     * corretor bateria de cara num 403 ao tentar abrir o cliente vinculado.
     */
    public function test_abrir_perfil_leva_corretor_direto_pra_seguros_nao_pra_visao_geral(): void
    {
        [$corretor, $perfil] = $this->criarCorretorVinculado();
        $this->actingAs($corretor);

        $resposta = $this->post(route('profile.switch', $perfil));

        $resposta->assertRedirect(route('insurance.index'));
    }

    public function test_abrir_perfil_continua_levando_consultor_pra_visao_geral(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);
        $this->actingAs($consultor);

        $resposta = $this->post(route('profile.switch', $perfil));

        $resposta->assertRedirect(route('dashboard'));
    }

    /** @return array{0: User, 1: FinancialProfile} */
    private function criarCorretorVinculado(): array
    {
        $corretor = User::factory()->broker()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        return [$corretor, $perfil];
    }
}
