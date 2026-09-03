<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Livewire\Admin\AdminUsers;
use App\Mail\ClientInviteMail;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\ClientOnboardingService;
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

    public function test_clientes_aparecem_agrupados_por_consultor_e_sem_consultor_a_parte(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $consultorA = User::factory()->consultant()->create();
        $consultorB = User::factory()->consultant()->create(); // sem clientes ainda

        $clienteA = User::factory()->create();
        $perfilA = FinancialProfile::factory()->create(['owner_user_id' => $clienteA->id]);
        ConsultantClient::create([
            'consultant_id' => $consultorA->id,
            'client_id' => $clienteA->id,
            'status' => ConsultantClientStatus::Active,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $clienteSemConsultor = User::factory()->create();
        $perfilSemConsultor = FinancialProfile::factory()->create(['owner_user_id' => $clienteSemConsultor->id]);

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->assertOk()
            ->assertViewHas('grupos', function ($grupos) use ($consultorA, $consultorB, $perfilA) {
                $porConsultor = $grupos->keyBy(fn (array $g) => $g['consultor']->id);

                self::assertTrue($porConsultor->get($consultorA->id)['perfis']->contains('id', $perfilA->id));
                self::assertTrue($porConsultor->get($consultorB->id)['perfis']->isEmpty());

                return true;
            })
            ->assertViewHas('perfisSemConsultor', function ($perfis) use ($perfilA, $perfilSemConsultor) {
                self::assertTrue($perfis->contains('id', $perfilSemConsultor->id));
                self::assertFalse($perfis->contains('id', $perfilA->id));

                return true;
            });
    }

    public function test_outras_contas_mostra_perfil_principal_e_consultor_do_conjuge(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $consultor = User::factory()->consultant()->create(['name' => 'Consultora Teste']);
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id, 'profile_name' => 'Família Teste']);
        ConsultantClient::create([
            'consultant_id' => $consultor->id,
            'client_id' => $titular->id,
            'status' => ConsultantClientStatus::Active,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $conjuge = app(ClientOnboardingService::class)->addPartner($perfil, 'Cônjuge Teste', 'conjuge.teste@exemplo.com', 'Senha123');

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->assertOk()
            ->assertViewHas('outrasContas', function ($outrasContas) use ($conjuge, $perfil, $consultor) {
                $linha = $outrasContas->firstWhere('user.id', $conjuge->id);

                self::assertNotNull($linha);
                self::assertSame($perfil->id, $linha['perfil']->id);
                self::assertSame($consultor->id, $linha['consultor']->id);

                return true;
            })
            ->assertSee('Família Teste')
            ->assertSee('Consultora Teste');
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

    public function test_admin_exclui_conta_dona_de_perfil_e_apaga_tudo_em_cascata(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        BankAccount::factory()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id]);
        ExpenseRecord::factory()->create(['profile_id' => $perfil->id]);

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('pedirExclusao', $cliente->id)
            ->set('confirmacaoExclusao', $cliente->email)
            ->call('excluirConta')
            ->assertHasNoErrors();

        self::assertSame(0, User::query()->where('id', $cliente->id)->count());
        self::assertSame(0, FinancialProfile::query()->where('id', $perfil->id)->count());
        self::assertSame(0, BankAccount::query()->where('profile_id', $perfil->id)->count());
        self::assertSame(0, ExpenseRecord::query()->where('profile_id', $perfil->id)->count());
    }

    public function test_admin_exclui_consultor_sem_afetar_dados_do_cliente(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ConsultantClient::create([
            'consultant_id' => $consultor->id,
            'client_id' => $cliente->id,
            'status' => ConsultantClientStatus::Active,
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('pedirExclusao', $consultor->id)
            ->set('confirmacaoExclusao', $consultor->email)
            ->call('excluirConta')
            ->assertHasNoErrors();

        self::assertSame(0, User::query()->where('id', $consultor->id)->count());
        self::assertSame(0, ConsultantClient::query()->where('consultant_id', $consultor->id)->count());
        self::assertSame(1, User::query()->where('id', $cliente->id)->count());
        self::assertSame(1, FinancialProfile::query()->where('id', $perfil->id)->count());
    }

    public function test_admin_nao_pode_excluir_a_propria_conta(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('pedirExclusao', $admin->id)
            ->set('confirmacaoExclusao', $admin->email)
            ->call('excluirConta')
            ->assertHasErrors(['confirmacaoExclusao']);

        self::assertSame(1, User::query()->where('id', $admin->id)->count());
    }

    public function test_confirmacao_com_email_errado_bloqueia_exclusao(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $cliente = User::factory()->create();
        FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('pedirExclusao', $cliente->id)
            ->set('confirmacaoExclusao', 'email-errado@exemplo.com')
            ->call('excluirConta')
            ->assertHasErrors(['confirmacaoExclusao']);

        self::assertSame(1, User::query()->where('id', $cliente->id)->count());
    }

    public function test_excluir_conjuge_com_login_proprio_so_remove_o_login(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);

        $conjuge = app(ClientOnboardingService::class)->addPartner($perfil, 'Cônjuge Teste', 'conjuge@exemplo.com', 'Senha123');
        $membro = $conjuge->memberships()->sole();

        $this->actingAs($admin);

        Livewire::test(AdminUsers::class)
            ->call('pedirExclusao', $conjuge->id)
            ->set('confirmacaoExclusao', $conjuge->email)
            ->call('excluirConta')
            ->assertHasNoErrors();

        self::assertSame(0, User::query()->where('id', $conjuge->id)->count());
        self::assertSame(1, FinancialProfile::query()->where('id', $perfil->id)->count());
        self::assertNull(ProfileMember::query()->find($membro->id)->user_id);
    }
}
