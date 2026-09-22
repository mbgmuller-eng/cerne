<?php

namespace Tests\Feature;

use App\Enums\InviteStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\AdminUsers;
use App\Mail\ProfessionalInviteMail;
use App\Models\ProfessionalInvite;
use App\Models\User;
use App\Services\ProfessionalOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Criação de conta profissional (Consultor ou Corretor) pelo admin — único
 * caminho de produção hoje, além do comando de terminal
 * `cerne:criar-consultor` (que continua existindo pra quem prefere).
 * Aceitar o convite (App\Http\Controllers\Auth\AcceptProfessionalInviteController)
 * cria a conta sem NENHUM perfil financeiro nem vínculo — o profissional
 * cria os próprios vínculos depois.
 */
class ProfessionalInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cria_convite_de_corretor(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('toggleProfessionalInviteForm')
            ->set('professionalName', 'Corretora Nova')
            ->set('professionalEmail', 'corretora.nova@exemplo.com')
            ->set('professionalRole', 'broker')
            ->call('inviteProfessional')
            ->assertHasNoErrors();

        $convite = ProfessionalInvite::query()->sole();
        self::assertSame('corretora.nova@exemplo.com', $convite->email);
        self::assertSame(UserRole::Broker, $convite->role);
        self::assertSame($admin->id, $convite->invited_by_user_id);
        Mail::assertQueued(ProfessionalInviteMail::class);
    }

    public function test_admin_cria_convite_de_consultor(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('toggleProfessionalInviteForm')
            ->set('professionalName', 'Consultor Novo')
            ->set('professionalEmail', 'consultor.novo@exemplo.com')
            ->set('professionalRole', 'consultant')
            ->call('inviteProfessional')
            ->assertHasNoErrors();

        $convite = ProfessionalInvite::query()->sole();
        self::assertSame(UserRole::Consultant, $convite->role);
    }

    public function test_convite_com_email_ja_existente_falha_a_validacao(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        User::factory()->create(['email' => 'ja-existe@exemplo.com']);
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('toggleProfessionalInviteForm')
            ->set('professionalName', 'Nome Qualquer')
            ->set('professionalEmail', 'ja-existe@exemplo.com')
            ->set('professionalRole', 'broker')
            ->call('inviteProfessional')
            ->assertHasErrors(['professionalEmail']);

        self::assertSame(0, ProfessionalInvite::query()->count());
    }

    public function test_papel_fora_de_consultor_ou_corretor_falha_a_validacao(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('toggleProfessionalInviteForm')
            ->set('professionalName', 'Nome Qualquer')
            ->set('professionalEmail', 'novo@exemplo.com')
            ->set('professionalRole', 'admin')
            ->call('inviteProfessional')
            ->assertHasErrors(['professionalRole']);
    }

    public function test_aceitar_convite_cria_conta_com_o_papel_certo_sem_perfil_nenhum(): void
    {
        ['invite' => $invite, 'token' => $token] = ProfessionalInvite::issue(null, 'Corretora Nova', 'corretora.aceita@exemplo.com', UserRole::Broker);

        $this->post(route('professional-invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertRedirect(route('dashboard'));

        $usuario = User::query()->where('email', 'corretora.aceita@exemplo.com')->sole();
        self::assertSame(UserRole::Broker, $usuario->role);
        self::assertTrue($usuario->is_active);
        self::assertNotNull($usuario->email_verified_at);

        self::assertSame(0, \App\Models\FinancialProfile::query()->where('owner_user_id', $usuario->id)->count());
        self::assertSame(InviteStatus::Accepted, $invite->fresh()->status);
        self::assertAuthenticatedAs($usuario);
    }

    public function test_convite_expirado_nao_pode_ser_aceito(): void
    {
        ['token' => $token] = ProfessionalInvite::issue(null, 'Nome', 'expirado@exemplo.com', UserRole::Consultant);
        ProfessionalInvite::query()->where('email', 'expirado@exemplo.com')->update(['expires_at' => now()->subDay()]);

        $this->post(route('professional-invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertSessionHasErrors('password');

        self::assertSame(0, User::query()->where('email', 'expirado@exemplo.com')->count());
    }

    public function test_reenviar_convite_profissional_expira_o_antigo_e_gera_um_novo(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        ['invite' => $original] = ProfessionalInvite::issue($admin, 'Nome', 'reenviar@exemplo.com', UserRole::Broker);
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('reenviarConviteProfissional', $original->id)
            ->assertHasNoErrors();

        self::assertSame(InviteStatus::Expired, $original->fresh()->status);
        self::assertSame(1, ProfessionalInvite::query()->where('status', InviteStatus::Pending)->count());
        Mail::assertQueued(ProfessionalInviteMail::class);
    }

    public function test_onboarding_service_rejeita_email_que_ja_tem_conta(): void
    {
        User::factory()->create(['email' => 'existente@exemplo.com']);
        ['invite' => $invite] = ProfessionalInvite::issue(null, 'Nome', 'existente@exemplo.com', UserRole::Consultant);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ProfessionalOnboardingService::class)->acceptInvite($invite, 'Senha123');
    }
}
