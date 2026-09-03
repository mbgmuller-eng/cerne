<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Admin\AdminUsers;
use App\Mail\ClientInviteMail;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Painel /admin: visão de toda a plataforma (não só os clientes de um
 * consultor) e o convite sem consultor (ver ClientOnboardingService::
 * acceptInvite() — o vínculo com consultor é pulado quando o convite não
 * tem um).
 */
class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_quem_nao_e_admin_recebe_403(): void
    {
        $consultor = User::factory()->consultant()->create();
        $this->actingAs($consultor);

        Livewire::test(AdminUsers::class)->assertStatus(403);
    }

    public function test_admin_ve_contas_e_perfis_de_todos_os_consultores(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $consultorA = User::factory()->consultant()->create();
        $consultorB = User::factory()->consultant()->create();

        $clienteA = User::factory()->create();
        FinancialProfile::factory()->create(['owner_user_id' => $clienteA->id]);
        ConsultantClient::create([
            'consultant_id' => $consultorA->id,
            'client_id' => $clienteA->id,
            'status' => ConsultantClientStatus::Active,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $clienteB = User::factory()->create();
        FinancialProfile::factory()->create(['owner_user_id' => $clienteB->id]);
        ConsultantClient::create([
            'consultant_id' => $consultorB->id,
            'client_id' => $clienteB->id,
            'status' => ConsultantClientStatus::Active,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($admin);

        $componente = Livewire::test(AdminUsers::class)->assertOk();
        $componente->assertSee($clienteA->email)
            ->assertSee($clienteB->email)
            ->assertSee($consultorA->name)
            ->assertSee($consultorB->name);
    }

    public function test_admin_gera_convite_sem_consultor(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Amigo do Marcelo')
            ->set('inviteEmail', 'amigo@exemplo.com')
            ->call('invite')
            ->assertHasNoErrors();

        $convite = ConsultantInvite::query()->where('client_email', 'amigo@exemplo.com')->sole();
        self::assertNull($convite->consultant_id);

        Mail::assertQueued(ClientInviteMail::class);
    }

    public function test_email_ja_cadastrado_bloqueia_o_convite_sem_consultor(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        User::factory()->create(['email' => 'ja-existe@exemplo.com']);
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Alguém')
            ->set('inviteEmail', 'ja-existe@exemplo.com')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);

        self::assertSame(0, ConsultantInvite::query()->count());
    }

    public function test_tela_de_aceite_renderiza_sem_consultor(): void
    {
        ['token' => $token] = ConsultantInvite::issue(null, 'Amigo Independente', 'independente@exemplo.com');

        $this->get(route('invite.accept', ['token' => $token]))
            ->assertOk()
            ->assertSee('Amigo Independente')
            ->assertDontSee('convidou você');
    }

    public function test_aceitar_convite_sem_consultor_nao_cria_vinculo_nenhum(): void
    {
        ['token' => $token] = ConsultantInvite::issue(null, 'Amigo Independente', 'independente@exemplo.com');

        $this->post(route('invite.store', ['token' => $token]), [
            'password' => 'Senha123',
            'password_confirmation' => 'Senha123',
        ])->assertRedirect(route('dashboard'));

        $usuario = User::query()->where('email', 'independente@exemplo.com')->sole();

        self::assertTrue(FinancialProfile::query()->where('owner_user_id', $usuario->id)->exists());
        self::assertSame(0, ConsultantClient::query()->where('client_id', $usuario->id)->count());
    }
}
