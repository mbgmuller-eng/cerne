<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminInsurers;
use App\Models\FinancialProfile;
use App\Models\Insurer;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInsurersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    private function criarPerfil(): FinancialProfile
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        app(ProfileContext::class)->set($perfil, $membro);

        return $perfil;
    }

    public function test_quem_nao_e_admin_recebe_403(): void
    {
        $this->actingAs(User::factory()->consultant()->create());

        Livewire::test(AdminInsurers::class)->assertStatus(403);
    }

    public function test_rota_so_abre_pra_admin(): void
    {
        $this->actingAs(User::factory()->consultant()->create())->get(route('admin.insurers'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('admin.insurers'))->assertOk()->assertSee('Seguradoras');
    }

    public function test_admin_ve_sugestoes_com_o_perfil_que_sugeriu(): void
    {
        $perfil = $this->criarPerfil();
        Insurer::resolveOrSuggest('Seguros do Vale');
        app(ProfileContext::class)->clear();

        $this->actingAs($this->admin());

        Livewire::test(AdminInsurers::class)
            ->assertSee('Seguros do Vale')
            ->assertSee($perfil->owner->email);
    }

    public function test_aprovar_promove_e_some_da_fila(): void
    {
        $this->criarPerfil();
        $sugestao = Insurer::resolveOrSuggest('Seguros do Vale');
        app(ProfileContext::class)->clear();
        $this->actingAs($this->admin());

        Livewire::test(AdminInsurers::class)
            ->set("corAprovacao.{$sugestao->id}", '#334455')
            ->call('aprovar', $sugestao->id);

        $sugestao->refresh();
        self::assertNull($sugestao->profile_id);
        self::assertSame('#334455', $sugestao->color_hex);
        self::assertSame(0, Insurer::withoutTaxonomyScope()->pending()->count());
    }

    public function test_dispensar_tira_da_fila_sem_apagar(): void
    {
        $this->criarPerfil();
        $sugestao = Insurer::resolveOrSuggest('Seguros do Vale');
        app(ProfileContext::class)->clear();
        $this->actingAs($this->admin());

        Livewire::test(AdminInsurers::class)->call('dispensar', $sugestao->id);

        self::assertNotNull($sugestao->fresh()->dismissed_at);
        self::assertSame(0, Insurer::withoutTaxonomyScope()->pending()->count());
    }

    public function test_admin_adiciona_seguradora_ja_aprovada_com_cor(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AdminInsurers::class)
            ->set('novoNome', 'Seguros do Vale')
            ->set('novaCor', '#112233')
            ->call('adicionar')
            ->assertHasNoErrors()
            ->assertSet('novoNome', '');

        $criada = Insurer::withoutTaxonomyScope()->where('name', 'Seguros do Vale')->sole();
        self::assertNull($criada->profile_id);
        self::assertSame('#112233', $criada->color_hex);
    }

    public function test_adicionar_nome_ja_na_lista_e_barrado_ignorando_acento_e_caixa(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AdminInsurers::class)
            ->set('novoNome', 'sulamerica')
            ->call('adicionar')
            ->assertHasErrors('novoNome');

        self::assertSame(1, Insurer::withoutTaxonomyScope()->shared()->whereRaw('LOWER(name) like ?', ['sul%'])->count());
    }

    public function test_adicionar_valida_nome_e_cor(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AdminInsurers::class)
            ->set('novoNome', '')
            ->set('novaCor', 'vermelho')
            ->call('adicionar')
            ->assertHasErrors(['novoNome', 'novaCor']);
    }

    public function test_adicionar_direto_apaga_sugestoes_pendentes_com_o_mesmo_nome(): void
    {
        $this->criarPerfil();
        Insurer::resolveOrSuggest('Seguros do Vale');
        app(ProfileContext::class)->clear();
        $this->actingAs($this->admin());

        Livewire::test(AdminInsurers::class)->set('novoNome', 'SEGUROS DO VALE')->call('adicionar');

        self::assertSame(0, Insurer::withoutTaxonomyScope()->pending()->count());
        self::assertSame(1, Insurer::withoutTaxonomyScope()->whereRaw('LOWER(name) = ?', ['seguros do vale'])->count());
    }

    public function test_editar_nome_e_cor(): void
    {
        $this->actingAs($this->admin());
        $azos = Insurer::withoutTaxonomyScope()->shared()->where('name', 'Azos')->sole();

        Livewire::test(AdminInsurers::class)
            ->call('iniciarEdicao', $azos->id)
            ->assertSet('editNome', 'Azos')
            ->set('editNome', 'Azos Seguros de Vida')
            ->set('editCor', '#445566')
            ->call('salvarEdicao')
            ->assertHasNoErrors()
            ->assertSet('editandoId', null);

        $azos->refresh();
        self::assertSame('Azos Seguros de Vida', $azos->name);
        self::assertSame('#445566', $azos->color_hex);
    }

    public function test_renomear_para_nome_de_outra_seguradora_e_barrado(): void
    {
        $this->actingAs($this->admin());
        $azos = Insurer::withoutTaxonomyScope()->shared()->where('name', 'Azos')->sole();

        Livewire::test(AdminInsurers::class)
            ->call('iniciarEdicao', $azos->id)
            ->set('editNome', 'allianz')
            ->call('salvarEdicao')
            ->assertHasErrors('editNome');

        self::assertSame('Azos', $azos->fresh()->name);
    }

    public function test_remover_nao_mexe_nas_apolices_ja_cadastradas(): void
    {
        $perfil = FinancialProfile::factory()->create();
        $apolice = \App\Models\InsurancePolicy::factory()->create(['profile_id' => $perfil->id, 'insurer_name' => 'Azos']);
        $this->actingAs($this->admin());
        $azos = Insurer::withoutTaxonomyScope()->shared()->where('name', 'Azos')->sole();

        Livewire::test(AdminInsurers::class)->call('remover', $azos->id);

        self::assertNull(Insurer::withoutTaxonomyScope()->find($azos->id));
        self::assertSame('Azos', \App\Models\InsurancePolicy::withoutProfileScope()->find($apolice->id)->insurer_name);
    }

    public function test_seletor_de_cor_da_fila_ja_vem_com_um_valor_e_cor_invalida_cai_no_padrao(): void
    {
        $this->criarPerfil();
        $sugestao = Insurer::resolveOrSuggest('Seguros do Vale');
        app(ProfileContext::class)->clear();
        $this->actingAs($this->admin());

        Livewire::test(AdminInsurers::class)
            ->assertSet("corAprovacao.{$sugestao->id}", '#64748B')
            ->set("corAprovacao.{$sugestao->id}", 'red; background-image: url(x)')
            ->call('aprovar', $sugestao->id);

        self::assertSame('#64748B', $sugestao->fresh()->color_hex);
    }
}
