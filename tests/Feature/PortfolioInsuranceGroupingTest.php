<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agrupamento de "Seguros da carteira" (consultor): tipo → cliente →
 * (membro, quando há mais de um dono no mesmo tipo/cliente, ou sempre em
 * Saúde) → seguradora → apólices.
 *
 * Caso real que motivou isso: o perfil de casal de um consultor tinha 4
 * apólices de vida (2 por cônjuge, AZOS+ICATU cada) e a tela antiga
 * mostrava 4 linhas com o MESMO nome de cliente — parecia duplicata. Não
 * era: eram duas pessoas diferentes no mesmo perfil. allActivePolicies()
 * agora carrega member_name, e este agrupamento separa por membro sempre
 * que há mais de um dono — ver ConsultantPortfolioService::
 * allActivePolicies() e PortfolioInsurance::group().
 */
class PortfolioInsuranceGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_casal_com_seguro_de_vida_em_duas_seguradoras_agrupa_por_pessoa_dentro_do_mesmo_cliente(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create(['name' => 'Marcelo Müller']);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $titular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id, 'name' => 'Marcelo']);
        $conjuge = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'name' => 'Helen']);

        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($titular, 'member')->create(['insurer_name' => 'AZOS']);
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($titular, 'member')->create(['insurer_name' => 'ICATU']);
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($conjuge, 'member')->create(['insurer_name' => 'AZOS']);
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($conjuge, 'member')->create(['insurer_name' => 'ICATU']);

        $this->actingAs($consultor);
        $grouped = Livewire::test(PortfolioInsurance::class)->viewData('grouped');

        self::assertCount(1, $grouped, 'só o tipo Vida tem apólice');
        $vida = $grouped->first();
        self::assertCount(1, $vida['clientes'], 'as 4 apólices são todas do mesmo cliente (perfil)');

        $doCliente = $vida['clientes']->first();
        self::assertTrue($doCliente['separarPorMembro'], 'duas pessoas diferentes no mesmo perfil — precisa separar');
        self::assertCount(2, $doCliente['membros'], 'um grupo por pessoa, não uma lista achatada de 4');

        foreach ($doCliente['membros'] as $porMembro) {
            self::assertContains($porMembro['nome'], ['Marcelo', 'Helen']);
            self::assertCount(2, $porMembro['seguradoras'], 'AZOS e ICATU, cada uma sua própria apólice');
        }
    }

    public function test_cliente_individual_com_um_seguro_de_carro_nao_separa_por_pessoa(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurance_type' => 'carro', 'insurer_name' => 'Allianz']);

        $this->actingAs($consultor);
        $grouped = Livewire::test(PortfolioInsurance::class)->viewData('grouped');

        self::assertFalse($grouped->first()['clientes']->first()['separarPorMembro']);
    }
}
