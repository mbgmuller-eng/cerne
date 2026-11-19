<?php

namespace Tests\Feature;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Livewire\GlobalSearch;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Busca global: só enxerga o que as telas de origem enxergam (perfil e privacidade do casal passam pelos escopos dos models),
 * não procura em campo cifrado e não abre para corretor.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id, 'role' => MemberRole::Primary]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
    }

    public function test_acha_em_varios_tipos_e_separa_por_grupo(): void
    {
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['description' => 'Pizzaria do Zé', 'member_id' => null]);
        FixedBill::factory()->create(['profile_id' => $this->perfil->id, 'name' => 'Pizza clube', 'member_id' => null]);
        Goal::create(['name' => 'Viagem para Pizza City', 'priority' => 1, 'estimated_value' => '1000.00', 'funding_method' => FundingMethod::LumpSum, 'current_amount' => '0.00', 'status' => GoalStatus::Active, 'created_by_user_id' => $this->usuario->id]);
        InvestmentRecord::factory()->create(['profile_id' => $this->perfil->id, 'member_id' => $this->membro->id, 'name' => 'CDB Pizza', 'created_by_user_id' => $this->usuario->id]);

        Livewire::test(GlobalSearch::class)->set('q', 'pizza')
            ->assertSeeHtml('data-busca-grupo="Despesas"')->assertSee('Pizzaria do Zé')
            ->assertSeeHtml('data-busca-grupo="Contas fixas"')->assertSee('Pizza clube')
            ->assertSeeHtml('data-busca-grupo="Objetivos"')->assertSee('Viagem para Pizza City')
            ->assertSeeHtml('data-busca-grupo="Investimentos"')->assertSee('CDB Pizza')
            ->assertDontSeeHtml('data-busca-grupo="Apólices"');
    }

    public function test_so_procura_com_duas_letras_ou_mais(): void
    {
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['description' => 'Almoço', 'member_id' => null]);

        Livewire::test(GlobalSearch::class)->set('q', 'a')->assertDontSee('Almoço')->assertSee('pelo menos 2 letras');
        Livewire::test(GlobalSearch::class)->set('q', 'Almo')->assertSee('Almoço');
    }

    public function test_curinga_do_like_vale_como_texto(): void
    {
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['description' => 'Almoço', 'member_id' => null]);
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['description' => 'Desconto 50% off', 'member_id' => null]);

        // "%%" não pode casar tudo: o "%" digitado é procurado como o caractere.
        Livewire::test(GlobalSearch::class)->set('q', '%%')->assertDontSee('Almoço')->assertDontSee('Desconto 50% off');
        Livewire::test(GlobalSearch::class)->set('q', '50%')->assertSee('Desconto 50% off')->assertDontSee('Almoço');
    }

    public function test_nao_mostra_o_que_outro_perfil_tem(): void
    {
        $outro = FinancialProfile::factory()->create(['owner_user_id' => User::factory()->create()->id]);
        ExpenseRecord::factory()->for($outro, 'profile')->create(['description' => 'Segredo alheio', 'member_id' => null]);

        Livewire::test(GlobalSearch::class)->set('q', 'Segredo')->assertDontSee('Segredo alheio')->assertSeeHtml('data-busca-vazia');
    }

    public function test_nao_mostra_o_que_o_conjuge_marcou_como_oculto(): void
    {
        $conjugeUsuario = User::factory()->create();
        $conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $conjugeUsuario->id]);

        ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['description' => 'Presente surpresa', 'member_id' => $conjuge->id, 'is_private' => true]);
        ExpenseRecord::factory()->for($this->perfil, 'profile')->create(['description' => 'Presente de aniversário', 'member_id' => $conjuge->id, 'is_private' => false]);

        Livewire::test(GlobalSearch::class)->set('q', 'Presente')->assertSee('Presente de aniversário')->assertDontSee('Presente surpresa');
    }

    public function test_apolice_se_acha_pela_seguradora_e_nao_pelo_nome_cifrado_do_segurado(): void
    {
        InsurancePolicy::factory()->create(['profile_id' => $this->perfil->id, 'insurer_name' => 'Seguradora Aurora', 'insured_person_name' => 'Fulano Cifrado', 'member_id' => null]);

        Livewire::test(GlobalSearch::class)->set('q', 'Aurora')->assertSeeHtml('data-busca-grupo="Apólices"')->assertSee('Seguradora Aurora');
        Livewire::test(GlobalSearch::class)->set('q', 'Cifrado')->assertDontSee('Seguradora Aurora');
    }

    public function test_corretor_nao_entra(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Broker]));

        $this->get(route('search.index'))->assertForbidden();
    }

    public function test_a_lupa_e_o_atalho_aparecem_no_perfil_e_nao_no_corretor(): void
    {
        $this->get(route('dashboard'))->assertOk()
            ->assertSeeHtml('data-busca-link')
            ->assertSee(route('search.index'), false)
            ->assertSeeHtml('data-voltar-topo');
    }

    public function test_a_pagina_abre(): void
    {
        $this->get(route('search.index', ['q' => 'ab']))->assertOk()->assertSee('Buscar');
    }
}
