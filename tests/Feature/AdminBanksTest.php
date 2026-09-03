<?php

namespace Tests\Feature;

use App\Livewire\Admin\AdminBanks;
use App\Models\Bank;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminBanksTest extends TestCase
{
    use RefreshDatabase;

    public function test_quem_nao_e_admin_recebe_403(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        Livewire::test(AdminBanks::class)->assertStatus(403);
    }

    public function test_admin_ve_sugestoes_de_qualquer_perfil(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);

        [$perfilA] = $this->criarPerfil();
        Bank::resolveOrSuggest('Cooperativa do Vale');

        [$perfilB] = $this->criarPerfil();
        Bank::resolveOrSuggest('Banco Regional XPTO');

        $this->actingAs($admin);

        Livewire::test(AdminBanks::class)
            ->assertOk()
            ->assertSee('Cooperativa do Vale')
            ->assertSee('Banco Regional XPTO')
            ->assertSee($perfilA->owner->email)
            ->assertSee($perfilB->owner->email);
    }

    public function test_aprovar_promove_o_banco_e_some_da_fila(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->criarPerfil();
        $banco = Bank::resolveOrSuggest('Cooperativa do Vale');

        $this->actingAs($admin);

        Livewire::test(AdminBanks::class)
            ->set("corAprovacao.{$banco->id}", '#334455')
            ->call('aprovar', $banco->id)
            ->assertHasNoErrors();

        $banco->refresh();
        self::assertNull($banco->profile_id);
        self::assertSame('#334455', $banco->color_hex);
        self::assertSame('#334455', Bank::colorFor('Cooperativa do Vale'));
    }

    public function test_dispensar_tira_da_fila_sem_afetar_quem_sugeriu(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->criarPerfil();
        $banco = Bank::resolveOrSuggest('Cooperativa do Vale');

        $this->actingAs($admin);

        Livewire::test(AdminBanks::class)
            ->call('dispensar', $banco->id)
            ->assertHasNoErrors()
            ->assertDontSee('Cooperativa do Vale');

        self::assertNotNull($banco->fresh());
        self::assertNotNull($banco->fresh()->dismissed_at);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember} */
    private function criarPerfil(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        app(ProfileContext::class)->set($perfil, $membro);

        return [$perfil, $membro];
    }
}
