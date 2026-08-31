<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Livewire\Consultant\PortfolioOverview;
use App\Mail\PartnerInviteMail;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\PartnerInvite;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O consultor também pode convidar o cônjuge de um cliente individual,
 * direto da Carteira — mesmo mecanismo que "Minha conta", disparado do
 * outro lado (ver PartnerInviteService, FinancialProfilePolicy::manageMembers).
 */
class PortfolioOverviewPartnerInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultor_convida_conjuge_de_cliente_vinculado(): void
    {
        Mail::fake();
        $consultor = User::factory()->consultant()->create();
        [$profile] = $this->criarClienteVinculado($consultor);

        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('togglePartnerInviteForm', $profile->id)
            ->set('partnerName', 'Helen Müller')
            ->set('partnerEmail', 'helen@exemplo.com')
            ->call('sendPartnerInvite')
            ->assertHasNoErrors();

        self::assertTrue(PartnerInvite::query()->where('profile_id', $profile->id)->exists());
        Mail::assertQueued(PartnerInviteMail::class);
    }

    public function test_consultor_nao_convida_conjuge_de_cliente_sem_vinculo(): void
    {
        $consultor = User::factory()->consultant()->create();
        $outroConsultor = User::factory()->consultant()->create();
        [$profile] = $this->criarClienteVinculado($outroConsultor);

        $this->actingAs($consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('togglePartnerInviteForm', $profile->id)
            ->set('partnerName', 'Helen Müller')
            ->set('partnerEmail', 'helen@exemplo.com')
            ->call('sendPartnerInvite')
            ->assertForbidden();

        self::assertSame(0, PartnerInvite::query()->where('profile_id', $profile->id)->count());
    }

    /** @return array{0: FinancialProfile, 1: User} */
    private function criarClienteVinculado(User $consultor): array
    {
        $titular = User::factory()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id,
            'client_id' => $titular->id,
            'status' => ConsultantClientStatus::Active,
        ]);

        $profile = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        ProfileMember::factory()->create(['profile_id' => $profile->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);

        return [$profile, $titular];
    }
}
