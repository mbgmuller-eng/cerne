<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Livewire\Profile\MyAccount;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ClientOnboardingService;
use App\Services\PartnerInviteService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Cônjuge sem login: um `ProfileMember` com `user_id` nulo dentro de um
 * casal — ele nunca pode logar, mas continua dono de contas/gastos/
 * investimentos por `member_id`, igual a qualquer outro membro.
 */
class PartnerWithoutLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_addpartnerwithoutlogin_cria_membro_sem_usuario_e_promove_o_perfil(): void
    {
        $owner = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $owner->id, 'profile_type' => 'single']);

        $membro = app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Mariana');

        self::assertNull($membro->user_id);
        self::assertSame('Mariana', $membro->name);
        self::assertSame(MemberRole::Secondary, $membro->role);
        self::assertSame(ProfileType::Couple, $perfil->fresh()->profile_type);
    }

    /**
     * `partner`/`canInvitePartner` são dado de view (passados só pro
     * `view(...)->with([...])` de render()), não propriedade pública do
     * componente — `Testable::get()` não os enxerga. A forma certa de
     * conferir aqui é pelo HTML renderizado, não por `->get(...)`.
     */
    public function test_myaccount_encontra_o_conjuge_sem_login_como_partner(): void
    {
        [$owner, $perfil, $membro] = $this->criarTitular();
        app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Mariana');

        Livewire::test(MyAccount::class)
            ->assertSee('Mariana')
            ->assertSee('Cadastrado sem login')
            ->assertDontSee('Convidar por e-mail');
    }

    public function test_convidar_por_email_depois_e_bloqueado(): void
    {
        [$owner, $perfil, $membro] = $this->criarTitular();
        app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Mariana');

        $this->expectException(RuntimeException::class);

        app(PartnerInviteService::class)->send($perfil, $owner, 'Mariana', 'mariana@example.com');
    }

    public function test_lancamento_privado_do_conjuge_sem_login_fica_invisivel_pro_titular(): void
    {
        [$owner, $perfil, $ownerMember] = $this->criarTitular();
        $marianaMember = app(ClientOnboardingService::class)->addPartnerWithoutLogin($perfil, 'Mariana');

        $privado = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'member_id' => $marianaMember->id, 'is_private' => true,
        ]);
        $visivel = ExpenseRecord::factory()->for($perfil, 'profile')->create([
            'member_id' => $marianaMember->id, 'is_private' => false,
        ]);

        app(ProfileContext::class)->set($perfil, $ownerMember);
        $visiveis = ExpenseRecord::all();

        self::assertFalse($visiveis->contains($privado));
        self::assertTrue($visiveis->contains($visivel));
    }

    public function test_livewire_cadastra_conjuge_sem_login_pela_tela(): void
    {
        [$owner, $perfil] = $this->criarTitular();

        Livewire::test(MyAccount::class)
            ->set('partnerOnlyName', 'Mariana')
            ->call('addPartnerWithoutLogin')
            ->assertHasNoErrors();

        $membro = ProfileMember::query()->where('profile_id', $perfil->id)->where('name', 'Mariana')->sole();
        self::assertNull($membro->user_id);
    }

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileMember} */
    private function criarTitular(): array
    {
        $owner = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $owner->id]);
        $ownerMember = ProfileMember::factory()->create([
            'profile_id' => $perfil->id, 'user_id' => $owner->id, 'role' => MemberRole::Primary,
        ]);
        $this->actingAs($owner);
        app(ProfileContext::class)->set($perfil, $ownerMember);

        return [$owner, $perfil, $ownerMember];
    }
}
