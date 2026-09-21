<?php

namespace Tests\Feature;

use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * InsurancePolicyBrokerScope: um corretor só vê a apólice com o próprio
 * broker_id — decidido apólice por apólice (ver InsuranceIndex), não por
 * uma categoria genérica. Consultor e dono nunca são restritos por aqui.
 */
class InsurancePolicyBrokerScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_corretor_ve_so_a_apolice_com_o_proprio_broker_id(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $corretorA = User::factory()->broker()->create();
        $corretorB = User::factory()->broker()->create();

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Da corretora A', 'broker_id' => $corretorA->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Da corretora B', 'broker_id' => $corretorB->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Sem corretor', 'broker_id' => null]);

        $this->actingAs($corretorA);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        $visiveis = InsurancePolicy::all();

        self::assertCount(1, $visiveis);
        self::assertSame('Da corretora A', $visiveis->first()->insurer_name);
    }

    public function test_consultor_financeiro_ve_todas_independente_do_broker_id(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $corretor = User::factory()->broker()->create();

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['broker_id' => $corretor->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['broker_id' => null]);

        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        self::assertCount(2, InsurancePolicy::all());
    }

    public function test_dono_do_perfil_ve_todas_as_proprias_apolices(): void
    {
        [$perfil, $membro, $titular] = $this->criarPerfil();
        $corretor = User::factory()->broker()->create();

        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['broker_id' => $corretor->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['broker_id' => null]);

        $this->actingAs($titular);
        app(ProfileContext::class)->set($perfil, $membro);

        self::assertCount(2, InsurancePolicy::all());
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember, 2: User} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);

        return [$perfil, $membro, $usuario];
    }
}
