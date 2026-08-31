<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Livewire\Profile\MyAccount;
use App\Mail\PartnerInviteMail;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class MyAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_mostra_meus_dados_e_meu_consultor(): void
    {
        $titular = User::factory()->create(['name' => 'Marcelo Müller', 'email' => 'marcelo@exemplo.com']);
        $profile = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create(['profile_id' => $profile->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);

        $consultor = User::factory()->consultant()->create(['name' => 'Marina Alencar']);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $titular->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($titular);
        app(ProfileContext::class)->set($profile, $profile->members->first());

        Livewire::test(MyAccount::class)
            ->assertSee('Marcelo Müller')
            ->assertSee('marcelo@exemplo.com')
            ->assertSee('Marina Alencar')
            ->assertViewHas('canInvitePartner', true);
    }

    public function test_titular_convida_o_conjuge_pela_tela(): void
    {
        Mail::fake();
        [$profile, $titular] = $this->criarPerfilIndividual();
        $this->actingAs($titular);
        app(ProfileContext::class)->set($profile, $profile->members->first());

        Livewire::test(MyAccount::class)
            ->call('toggleInviteForm')
            ->set('partnerName', 'Helen Müller')
            ->set('partnerEmail', 'helen@exemplo.com')
            ->call('invitePartner')
            ->assertHasNoErrors();

        self::assertTrue(PartnerInvite::query()->where('profile_id', $profile->id)->exists());
        Mail::assertQueued(PartnerInviteMail::class);
    }

    public function test_conjuge_secundario_ve_o_titular_como_seu_par(): void
    {
        [$profile, $titular] = $this->criarPerfilIndividual();
        $conjugeUser = User::factory()->create(['name' => 'Helen Müller']);
        ProfileMember::factory()->secondary()->create([
            'profile_id' => $profile->id,
            'user_id' => $conjugeUser->id,
            'name' => 'Helen Müller',
        ]);

        $this->actingAs($conjugeUser);
        app(ProfileContext::class)->set($profile, $profile->members()->where('user_id', $conjugeUser->id)->first());

        Livewire::test(MyAccount::class)
            ->assertSee('Marcelo')
            ->assertViewHas('canInvitePartner', false);
    }

    /** @return array{0: FinancialProfile, 1: User} */
    private function criarPerfilIndividual(): array
    {
        $titular = User::factory()->create(['name' => 'Marcelo Müller']);
        $profile = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create(['profile_id' => $profile->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary, 'name' => 'Marcelo Müller']);

        return [$profile, $titular];
    }
}
