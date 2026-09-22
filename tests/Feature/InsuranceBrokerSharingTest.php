<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Compartilhar com corretor" — quem cadastra/edita a apólice decide, na
 * hora, se ela aparece pra algum corretor vinculado (ver
 * InsuranceIndex::resolveBroker()). A última palavra é de quem cadastra
 * (cliente ou consultor); o corretor nunca escolhe sozinho.
 */
class InsuranceBrokerSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_de_corretores_disponiveis_so_traz_vinculo_ativo_deste_perfil(): void
    {
        [$perfil, , $titular] = $this->criarPerfil();

        $corretorAtivo = User::factory()->broker()->create(['name' => 'Corretora Ativa']);
        ConsultantClient::factory()->create([
            'consultant_id' => $corretorAtivo->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $corretorPendente = User::factory()->broker()->create(['name' => 'Corretora Pendente']);
        ConsultantClient::factory()->pending()->create([
            'consultant_id' => $corretorPendente->id, 'client_id' => $titular->id,
        ]);

        $component = Livewire::test(InsuranceIndex::class);
        $disponiveis = $component->get('availableBrokers');

        self::assertCount(1, $disponiveis);
        self::assertSame('Corretora Ativa', $disponiveis->first()->name);
    }

    public function test_cadastrar_apolice_compartilhando_com_corretor_grava_broker_id(): void
    {
        [, , $titular] = $this->criarPerfil();
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        Livewire::test(InsuranceIndex::class)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Icatu Seguros')
            ->set('policyMonthlyPremium', '100.00')
            ->set('policyStartDate', '2026-01-01')
            ->set('policyBrokerId', $corretor->id)
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Icatu Seguros')->sole();
        self::assertSame($corretor->id, $apolice->broker_id);
    }

    public function test_apolice_sem_corretor_escolhido_fica_com_broker_id_nulo(): void
    {
        [, , $titular] = $this->criarPerfil();
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        Livewire::test(InsuranceIndex::class)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Sem Compartilhar')
            ->set('policyMonthlyPremium', '100.00')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Sem Compartilhar')->sole();
        self::assertNull($apolice->broker_id);
    }

    public function test_corretor_fora_da_lista_de_vinculados_nao_e_aceito(): void
    {
        $this->criarPerfil();
        $corretorNaoVinculado = User::factory()->broker()->create();

        Livewire::test(InsuranceIndex::class)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Tentativa')
            ->set('policyMonthlyPremium', '100.00')
            ->set('policyStartDate', '2026-01-01')
            ->set('policyBrokerId', $corretorNaoVinculado->id)
            ->call('savePolicy')
            ->assertHasErrors(['policyBrokerId']);

        self::assertSame(0, InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Tentativa')->count());
    }

    public function test_editar_apolice_pode_desligar_o_compartilhamento(): void
    {
        [$perfil, $membro, $titular] = $this->criarPerfil();
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active,
        ]);
        $apolice = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['broker_id' => $corretor->id]);

        Livewire::test(InsuranceIndex::class)
            ->call('editPolicy', $apolice->id)
            ->assertSet('policyBrokerId', $corretor->id)
            ->set('policyBrokerId', '')
            ->call('savePolicy')
            ->assertHasNoErrors();

        self::assertNull($apolice->fresh()->broker_id);
    }

    public function test_corretor_vinculado_ve_a_propria_apolice_compartilhada_na_tela_do_cliente(): void
    {
        [$perfil, $membro, $titular] = $this->criarPerfil();
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Compartilhada', 'broker_id' => $corretor->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Nao compartilhada', 'broker_id' => null]);

        $this->actingAs($corretor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        $policies = Livewire::test(InsuranceIndex::class)->get('policies');

        self::assertCount(1, $policies);
        self::assertSame('Compartilhada', $policies->first()->insurer_name);
    }

    public function test_corretor_cadastrando_apolice_nova_ja_fica_compartilhada_com_ele_mesmo(): void
    {
        [$perfil, , $titular] = $this->criarPerfil();
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($corretor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(InsuranceIndex::class)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Cadastrada pelo corretor')
            ->set('policyMonthlyPremium', '100.00')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Cadastrada pelo corretor')->sole();
        self::assertSame($corretor->id, $apolice->broker_id);
    }

    public function test_corretor_nao_ve_o_campo_de_escolher_corretor_no_formulario(): void
    {
        [$perfil, , $titular] = $this->criarPerfil();
        $corretor = User::factory()->broker()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $corretor->id, 'client_id' => $titular->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($corretor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        Livewire::test(InsuranceIndex::class)->assertDontSee('Compartilhar com corretor');
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember, 2: User} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro, $usuario];
    }
}
