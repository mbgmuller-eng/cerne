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
 * CRUD de apólice — tela do cliente. Diferente de conta/cartão, o membro
 * é opcional (em branco = "seguro familiar", ver migration da coluna
 * member_id), então o teste de tenancy aqui cobre tanto o id de outro
 * perfil quanto o caso legítimo de deixar em branco.
 */
class InsuranceCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_cadastra_apolice_nova(): void
    {
        [$perfil, $membro] = $this->criarPerfil();

        Livewire::test(InsuranceIndex::class)
            ->set('policyMemberId', $membro->id)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Icatu Seguros')
            ->set('policyInsuredItem', '')
            ->set('policyCoverageAmount', '500000')
            ->set('policyMonthlyPremium', '250.00')
            ->set('policyPaymentFrequency', 'monthly')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Icatu Seguros')->sole();
        self::assertSame($perfil->id, $apolice->profile_id);
        self::assertSame($membro->id, $apolice->member_id);
        self::assertSame('250.00', $apolice->monthly_premium);
        self::assertTrue($apolice->is_active);
    }

    public function test_cadastrar_sem_membro_vira_seguro_familiar(): void
    {
        $this->criarPerfil();

        Livewire::test(InsuranceIndex::class)
            ->set('policyMemberId', '')
            ->set('policyInsuranceType', 'residencia')
            ->set('policyInsurerName', 'Porto Seguro')
            ->set('policyMonthlyPremium', '80.00')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Porto Seguro')->sole();
        self::assertNull($apolice->member_id);
    }

    public function test_item_segurado_e_gravado_para_carro(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InsuranceIndex::class)
            ->set('policyMemberId', $membro->id)
            ->set('policyInsuranceType', 'carro')
            ->set('policyInsurerName', 'Allianz')
            ->set('policyInsuredItem', 'Honda Civic 2022')
            ->set('policyMonthlyPremium', '0')
            ->set('policyAnnualPremium', '2400.00')
            ->set('policyPaymentFrequency', 'annual')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Allianz')->sole();
        self::assertSame('Honda Civic 2022', $apolice->insured_item);
    }

    public function test_pessoa_sem_membro_cadastrado_usa_o_nome_livre(): void
    {
        $this->criarPerfil();

        Livewire::test(InsuranceIndex::class)
            ->set('policyMemberId', '')
            ->set('policyInsuredPersonName', 'Filha')
            ->set('policyInsuranceType', 'saude')
            ->set('policyInsurerName', 'Amil')
            ->set('policyMonthlyPremium', '350.00')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Amil')->sole();
        self::assertNull($apolice->member_id);
        self::assertSame('Filha', $apolice->insured_person_name);
        self::assertSame('Filha', $apolice->personLabel());
    }

    public function test_nome_livre_e_descartado_quando_ha_membro_selecionado(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InsuranceIndex::class)
            ->set('policyMemberId', $membro->id)
            ->set('policyInsuredPersonName', 'Nome que não deveria ser salvo')
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Icatu')
            ->set('policyMonthlyPremium', '100.00')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $apolice = InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Icatu')->sole();
        self::assertSame($membro->id, $apolice->member_id);
        self::assertNull($apolice->insured_person_name);
    }

    public function test_membro_de_outro_perfil_nao_e_aceito(): void
    {
        $this->criarPerfil();

        $outroPerfil = FinancialProfile::factory()->create();
        $membroDeOutroPerfil = ProfileMember::factory()->create(['profile_id' => $outroPerfil->id]);

        Livewire::test(InsuranceIndex::class)
            ->set('policyMemberId', $membroDeOutroPerfil->id)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Tentativa')
            ->set('policyMonthlyPremium', '10.00')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasErrors(['policyMemberId']);

        self::assertSame(0, InsurancePolicy::withoutProfileScope()->where('insurer_name', 'Tentativa')->count());
    }

    public function test_editar_apolice_atualiza_os_dados(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $apolice = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')
            ->create(['insurer_name' => 'Nome Antigo', 'monthly_premium' => '100.00']);

        Livewire::test(InsuranceIndex::class)
            ->call('editPolicy', $apolice->id)
            ->set('policyInsurerName', 'Nome Novo')
            ->call('savePolicy')
            ->assertHasNoErrors();

        self::assertSame('Nome Novo', $apolice->fresh()->insurer_name);
    }

    public function test_excluir_apolice_remove_de_verdade(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        $apolice = InsurancePolicy::factory()->for($perfil, 'profile')->for($membro, 'member')->create();

        Livewire::test(InsuranceIndex::class)->call('deletePolicy', $apolice->id);

        self::assertNull(InsurancePolicy::withoutProfileScope()->find($apolice->id));
    }

    public function test_mensalidade_e_obrigatoria(): void
    {
        [, $membro] = $this->criarPerfil();

        Livewire::test(InsuranceIndex::class)
            ->set('policyMemberId', $membro->id)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', 'Icatu')
            ->set('policyMonthlyPremium', '')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasErrors(['policyMonthlyPremium']);
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
