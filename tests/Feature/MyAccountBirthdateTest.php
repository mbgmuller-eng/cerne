<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Livewire\Profile\MyAccount;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Aniversário do titular/cônjuge, editável em "Minha conta" — a própria
 * pessoa edita a própria data; a data do cônjuge é gestão de perfil, exige
 * manageMembers (mesma policy de convidar/cadastrar cônjuge).
 */
class MyAccountBirthdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_titular_edita_o_proprio_aniversario(): void
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);

        $this->actingAs($titular);
        app(ProfileContext::class)->set($perfil, $membro);

        Livewire::test(MyAccount::class)
            ->call('toggleOwnBirthdate')
            ->set('ownBirthdateInput', '1988-04-12')
            ->call('saveOwnBirthdate')
            ->assertHasNoErrors();

        self::assertSame('1988-04-12', $membro->fresh()->birthdate->toDateString());
    }

    public function test_data_no_futuro_falha_a_validacao(): void
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);

        $this->actingAs($titular);
        app(ProfileContext::class)->set($perfil, $membro);

        Livewire::test(MyAccount::class)
            ->call('toggleOwnBirthdate')
            ->set('ownBirthdateInput', now()->addDay()->toDateString())
            ->call('saveOwnBirthdate')
            ->assertHasErrors(['ownBirthdateInput']);

        self::assertNull($membro->fresh()->birthdate);
    }

    public function test_titular_edita_o_aniversario_do_conjuge(): void
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $titular->id]);
        $membroTitular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);
        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);

        $this->actingAs($titular);
        app(ProfileContext::class)->set($perfil, $membroTitular);

        Livewire::test(MyAccount::class)
            ->call('togglePartnerBirthdate')
            ->set('partnerBirthdateInput', '1991-11-02')
            ->call('savePartnerBirthdate')
            ->assertHasNoErrors();

        self::assertSame('1991-11-02', $membroConjuge->fresh()->birthdate->toDateString());
    }

    public function test_conjuge_secundario_nao_pode_editar_o_aniversario_do_titular(): void
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id]);
        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);

        $this->actingAs($conjuge);
        app(ProfileContext::class)->set($perfil, $membroConjuge);

        Livewire::test(MyAccount::class)
            ->call('togglePartnerBirthdate')
            ->set('partnerBirthdateInput', '1991-11-02')
            ->call('savePartnerBirthdate')
            ->assertStatus(403);
    }
}
