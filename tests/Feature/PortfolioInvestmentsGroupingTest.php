<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioInvestments;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agrupamento de "Investimentos da carteira" (consultor): cliente →
 * (membro, quando há mais de um dono no mesmo cliente) → instituição →
 * ativos — pedido pelo usuário pra parar de repetir o nome do cliente
 * em toda linha, mesmo corte já usado em "Seguros da carteira" (ver
 * PortfolioInsuranceGroupingTest).
 */
class PortfolioInvestmentsGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_casal_com_ativos_em_duas_instituicoes_agrupa_por_pessoa_dentro_do_mesmo_cliente(): void
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

        InvestmentRecord::factory()->for($perfil, 'profile')->for($titular, 'member')->create(['institution' => 'XP Investimentos']);
        InvestmentRecord::factory()->for($perfil, 'profile')->for($titular, 'member')->create(['institution' => 'BTG Pactual']);
        InvestmentRecord::factory()->for($perfil, 'profile')->for($conjuge, 'member')->create(['institution' => 'XP Investimentos']);

        $this->actingAs($consultor);
        $grouped = Livewire::test(PortfolioInvestments::class)->viewData('grouped');

        self::assertCount(1, $grouped, 'os 3 ativos são todos do mesmo cliente (perfil)');
        $doCliente = $grouped->first();
        self::assertTrue($doCliente['separarPorMembro'], 'duas pessoas diferentes no mesmo perfil — precisa separar');
        self::assertCount(2, $doCliente['membros'], 'um grupo por pessoa, não uma lista achatada de 3');

        $membroTitular = $doCliente['membros']->firstWhere('nome', 'Marcelo');
        self::assertCount(2, $membroTitular['instituicoes'], 'XP e BTG, cada uma seu próprio ativo');

        $membroConjuge = $doCliente['membros']->firstWhere('nome', 'Helen');
        self::assertCount(1, $membroConjuge['instituicoes']);
    }

    public function test_cliente_individual_com_ativos_em_duas_instituicoes_nao_separa_por_pessoa(): void
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
        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['institution' => 'XP Investimentos']);
        InvestmentRecord::factory()->for($perfil, 'profile')->for($membro, 'member')->create(['institution' => 'Nubank']);

        $this->actingAs($consultor);
        $grouped = Livewire::test(PortfolioInvestments::class)->viewData('grouped');

        $doCliente = $grouped->first();
        self::assertFalse($doCliente['separarPorMembro']);
        self::assertCount(1, $doCliente['membros']);
        self::assertCount(2, $doCliente['membros']->first()['instituicoes'], 'XP e Nubank continuam separadas, só não por pessoa');
    }

    public function test_dois_clientes_diferentes_nao_se_misturam(): void
    {
        $consultor = User::factory()->consultant()->create();

        $clienteA = User::factory()->create(['name' => 'Cliente A']);
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $clienteA->id, 'status' => ConsultantClientStatus::Active]);
        $perfilA = FinancialProfile::factory()->create(['owner_user_id' => $clienteA->id]);
        $membroA = ProfileMember::factory()->create(['profile_id' => $perfilA->id, 'user_id' => $clienteA->id]);
        InvestmentRecord::factory()->for($perfilA, 'profile')->for($membroA, 'member')->create();

        $clienteB = User::factory()->create(['name' => 'Cliente B']);
        ConsultantClient::factory()->create(['consultant_id' => $consultor->id, 'client_id' => $clienteB->id, 'status' => ConsultantClientStatus::Active]);
        $perfilB = FinancialProfile::factory()->create(['owner_user_id' => $clienteB->id]);
        $membroB = ProfileMember::factory()->create(['profile_id' => $perfilB->id, 'user_id' => $clienteB->id]);
        InvestmentRecord::factory()->for($perfilB, 'profile')->for($membroB, 'member')->create();

        $this->actingAs($consultor);
        $grouped = Livewire::test(PortfolioInvestments::class)->viewData('grouped');

        self::assertCount(2, $grouped);
        self::assertEqualsCanonicalizing(['Cliente A', 'Cliente B'], $grouped->keys()->all());
    }
}
