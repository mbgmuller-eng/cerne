<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Consultant\PortfolioInsurance;
use App\Livewire\Insurance\InsuranceIndex;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\Insurer;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O cadastro de seguradoras (Insurer) entra na tela de apólice como lista de
 * sugestão, fila de aprovação e cor de marca no selo — sem nunca trocar o
 * texto que a apólice guarda.
 */
class InsuranceInsurerCatalogTest extends TestCase
{
    use RefreshDatabase;

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

    private function salvar(string $seguradora): void
    {
        Livewire::test(InsuranceIndex::class)
            ->set('policyInsuranceType', 'vida')
            ->set('policyInsurerName', $seguradora)
            ->set('policyMonthlyPremium', '100.00')
            ->set('policyStartDate', '2026-01-01')
            ->call('savePolicy')
            ->assertHasNoErrors();
    }

    public function test_salvar_apolice_com_seguradora_nova_cria_sugestao_e_mantem_o_texto_digitado(): void
    {
        [$perfil] = $this->criarPerfil();

        $this->salvar('Seguros do Vale');

        $apolice = InsurancePolicy::withoutProfileScope()->where('profile_id', $perfil->id)->sole();
        self::assertSame('Seguros do Vale', $apolice->insurer_name);

        $sugestao = Insurer::withoutTaxonomyScope()->pending()->sole();
        self::assertSame('Seguros do Vale', $sugestao->name);
        self::assertSame($perfil->id, $sugestao->profile_id);
    }

    public function test_seguradora_conhecida_nao_gera_sugestao_mesmo_com_grafia_diferente(): void
    {
        $this->criarPerfil();

        $this->salvar('sulamerica');
        $this->salvar('AZOS');
        $this->salvar('Porto');

        self::assertSame(0, Insurer::withoutTaxonomyScope()->pending()->count());
        // A apólice guarda o que foi digitado, nunca o nome oficial.
        self::assertSame(['AZOS', 'Porto', 'sulamerica'], InsurancePolicy::withoutProfileScope()->orderBy('insurer_name')->pluck('insurer_name')->all());
    }

    public function test_formulario_oferece_as_seguradoras_aprovadas_e_as_minhas_sugestoes(): void
    {
        $this->criarPerfil();
        Insurer::resolveOrSuggest('Seguros do Vale');

        Livewire::test(InsuranceIndex::class)
            ->assertSeeHtml('<datalist id="known-insurers">')
            ->assertSeeHtml('<option value="Porto Seguro"></option>')
            ->assertSeeHtml('<option value="Seguros do Vale"></option>');
    }

    public function test_sugestao_de_outro_cliente_nao_aparece_na_lista_de_quem_digita(): void
    {
        $this->criarPerfil();
        Insurer::resolveOrSuggest('Seguros do Vale');

        $this->criarPerfil();

        Livewire::test(InsuranceIndex::class)->assertDontSeeHtml('<option value="Seguros do Vale"></option>');
    }

    public function test_selo_usa_a_cor_de_marca_cadastrada(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        InsurancePolicy::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'insurer_name' => 'Allianz']);

        Livewire::test(InsuranceIndex::class)->assertSeeHtml('background: #003781');
    }

    public function test_seguradora_sem_cadastro_continua_com_a_cor_sorteada(): void
    {
        [$perfil, $membro] = $this->criarPerfil();
        InsurancePolicy::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'insurer_name' => 'Seguradora Sem Cadastro']);

        Livewire::test(InsuranceIndex::class)
            ->assertDontSeeHtml('background: #')
            ->assertSee('Seguradora Sem Cadastro');
    }

    public function test_carteira_do_consultor_tambem_pinta_o_selo_com_a_cor_de_marca(): void
    {
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        InsurancePolicy::factory()->create(['profile_id' => $perfil->id, 'insurer_name' => 'Allianz']);
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);
        $this->actingAs($consultor);

        Livewire::test(PortfolioInsurance::class)->assertSeeHtml('background: #003781');
    }
}
