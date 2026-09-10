<?php

namespace Tests\Feature;

use App\Livewire\Insurance\InsuranceIndex;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agrupamento da tela "Seus seguros": tipo → (membro, quando há mais de
 * um dono no mesmo tipo, ou sempre em Saúde) → seguradora → apólices.
 *
 * O caso que motivou isso: um casal com duas apólices de vida cada (AZOS
 * + ICATU) parecia "duplicado" na tela antiga, que não separava por
 * pessoa — eram 4 apólices legítimas, só faltava dizer de quem era cada
 * uma (ver getGroupedProperty()).
 */
class InsuranceGroupingTest extends TestCase
{
    use RefreshDatabase;

    public function test_casal_com_seguro_de_vida_nas_duas_seguradoras_separa_por_pessoa(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $titular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id, 'name' => 'Marcelo']);
        $conjuge = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'name' => 'Helen']);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $titular);

        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($titular, 'member')->create(['insurer_name' => 'AZOS']);
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($titular, 'member')->create(['insurer_name' => 'ICATU']);
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($conjuge, 'member')->create(['insurer_name' => 'AZOS']);
        InsurancePolicy::factory()->life()->for($perfil, 'profile')->for($conjuge, 'member')->create(['insurer_name' => 'ICATU']);

        $grouped = Livewire::test(InsuranceIndex::class)->get('grouped');

        self::assertCount(1, $grouped, 'só o tipo Vida tem apólice');
        $vida = $grouped->first();
        self::assertTrue($vida['separarPorMembro']);
        self::assertCount(2, $vida['membros'], 'um grupo por pessoa');

        foreach ($vida['membros'] as $porMembro) {
            self::assertContains($porMembro['nome'], ['Marcelo', 'Helen']);
            self::assertCount(2, $porMembro['seguradoras'], 'AZOS e ICATU, cada uma sua própria apólice');
            foreach ($porMembro['seguradoras'] as $apolices) {
                self::assertCount(1, $apolices);
            }
        }
    }

    public function test_saude_sempre_separa_por_pessoa_mesmo_com_um_membro_so(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurance_type' => 'saude', 'insurer_name' => 'SulAmérica']);

        $grouped = Livewire::test(InsuranceIndex::class)->get('grouped');

        self::assertTrue($grouped->first()['separarPorMembro']);
    }

    /**
     * O app não tem papel de dependente — pai e filha compartilham o
     * mesmo perfil individual, só o pai é ProfileMember. A apólice da
     * filha usa insured_person_name (sem member_id), e ainda assim tem
     * que separar dela do pai na tela — ver InsurancePolicy::
     * personGroupKey()/personLabel().
     */
    public function test_saude_separa_titular_de_dependente_sem_membro_cadastrado(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurance_type' => 'saude', 'insurer_name' => 'Amil']);
        InsurancePolicy::factory()->for($perfil, 'profile')
            ->create(['insurance_type' => 'saude', 'insurer_name' => 'Amil', 'member_id' => null, 'insured_person_name' => 'Filha']);

        $grouped = Livewire::test(InsuranceIndex::class)->get('grouped');

        $saude = $grouped->first();
        self::assertTrue($saude['separarPorMembro']);
        self::assertCount(2, $saude['membros']);

        $nomes = $saude['membros']->pluck('nome')->all();
        self::assertContains('Filha', $nomes);
        self::assertContains($membro->name, $nomes);
    }

    public function test_carro_com_um_membro_so_nao_separa_por_pessoa(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurance_type' => 'carro', 'insurer_name' => 'Allianz', 'insured_item' => 'Honda Civic']);

        $grouped = Livewire::test(InsuranceIndex::class)->get('grouped');

        self::assertFalse($grouped->first()['separarPorMembro']);
        self::assertCount(1, $grouped->first()['membros']);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}
