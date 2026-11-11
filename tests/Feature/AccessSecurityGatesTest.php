<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverGrantBasis;
use App\Enums\CaregiverStatus;
use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Livewire\Consultant\PortfolioOverview;
use App\Livewire\Profile\AccessApprovals;
use App\Mail\CaregiverInviteMail;
use App\Mail\PartnerInviteMail;
use App\Models\ConsultantClient;
use App\Models\ConsultantInvite;
use App\Models\FinancialProfile;
use App\Models\PartnerInvite;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\ConsultantAccessRequested;
use App\Services\CaregiverService;
use App\Services\ClientOnboardingService;
use App\Services\PartnerInviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Revisão de segurança (2026-11): o consultor vinculado NÃO pode (1) trocar a senha de um cliente que já ativou a conta pelo convite de acesso e
 * (2) criar para si um login dentro do perfil do cliente (cônjuge ou familiar com saúde) usando um e-mail que ele controla. O que o consultor monta
 * em nome do cliente só vale depois que o dono do perfil aprova, e o consultor nunca recebe o link.
 */
class AccessSecurityGatesTest extends TestCase
{
    use RefreshDatabase;

    private User $consultor;

    private User $cliente;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultor = User::factory()->consultant()->create();
        $this->cliente = User::factory()->create(['name' => 'Cliente Ativo', 'email' => 'cliente@exemplo.com']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->cliente->id, 'role' => MemberRole::Primary, 'name' => 'Cliente Ativo']);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active]);
    }

    // ------------------------------------------------------------------ troca de senha por convite

    public function test_convite_nao_troca_a_senha_de_conta_ja_ativada(): void
    {
        $this->cliente->update(['password' => 'SenhaDoCliente1']);
        ['token' => $token] = ConsultantInvite::issue($this->consultor, 'Cliente Ativo', 'cliente@exemplo.com');

        $this->post(route('invite.store', ['token' => $token]), ['password' => 'SenhaDoConsultor1', 'password_confirmation' => 'SenhaDoConsultor1'])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('SenhaDoCliente1', $this->cliente->fresh()->password), 'a senha do cliente não pode mudar');
        $this->assertGuest();
    }

    public function test_enviar_convite_de_acesso_e_proibido_para_cliente_que_ja_ativou(): void
    {
        $this->actingAs($this->consultor);

        Livewire::test(PortfolioOverview::class)->call('enviarConviteDeAcesso', $this->perfil->id)->assertForbidden();
    }

    public function test_conta_criada_pelo_profissional_nasce_pendente_e_o_convite_ativa_so_ela(): void
    {
        $gerenciado = app(ClientOnboardingService::class)->createManagedClient($this->consultor, 'Importado', 'importado@exemplo.com');
        $this->assertTrue($gerenciado->refresh()->password_pending);

        ['token' => $token] = ConsultantInvite::issue($this->consultor, 'Importado', 'importado@exemplo.com');
        $this->post(route('invite.store', ['token' => $token]), ['password' => 'NovaSenha123', 'password_confirmation' => 'NovaSenha123'])->assertRedirect(route('dashboard'));

        $this->assertFalse($gerenciado->refresh()->password_pending, 'depois de ativar, o convite nunca mais troca a senha');
        $this->assertNotNull($gerenciado->email_verified_at);
    }

    public function test_o_consultor_nao_recebe_o_link_do_convite(): void
    {
        Mail::fake();
        $this->actingAs($this->consultor);

        Livewire::test(PortfolioOverview::class)
            ->call('toggleInviteForm')
            ->set('inviteName', 'Novo Cliente')->set('inviteEmail', 'novo@exemplo.com')
            ->call('invite')
            ->assertSet('lastInviteLink', null);

        Mail::assertQueued(\App\Mail\ClientInviteMail::class);
    }

    // ------------------------------------------------------------------ cônjuge criado pelo consultor

    public function test_convite_de_conjuge_do_consultor_nao_vale_antes_da_aprovacao_do_dono(): void
    {
        Mail::fake();

        $link = app(PartnerInviteService::class)->send($this->perfil, $this->consultor, 'Alias', 'alias@exemplo.com');

        $this->assertNull($link);
        $convite = PartnerInvite::query()->sole();
        $this->assertNull($convite->owner_approved_at);
        $this->assertNull(PartnerInvite::findValid('qualquer'), 'sem token válido');

        // Mesmo com o token original em mãos, o convite não funciona: ele nunca foi liberado.
        ['token' => $tokenOriginal] = PartnerInvite::issue($this->perfil, $this->consultor, 'Alias2', 'alias2@exemplo.com', false);
        $this->assertNull(PartnerInvite::findValid($tokenOriginal));
        $this->post(route('partner-invite.store', ['token' => $tokenOriginal]), ['password' => 'Senha1234', 'password_confirmation' => 'Senha1234'])->assertSessionHasErrors('password');
        $this->assertSame(0, User::query()->where('email', 'alias2@exemplo.com')->count());
        Mail::assertNothingQueued();
    }

    public function test_so_o_dono_aprova_e_so_depois_o_email_sai(): void
    {
        Mail::fake();
        Notification::fake();
        app(PartnerInviteService::class)->send($this->perfil, $this->consultor, 'Helen', 'helen@exemplo.com');
        Notification::assertSentTo($this->cliente, ConsultantAccessRequested::class);

        $convite = PartnerInvite::query()->sole();

        // O consultor (ou qualquer outra pessoa) não aprova.
        $this->actingAs($this->consultor);
        Livewire::test(AccessApprovals::class)->call('approvePartner', $convite->id)->assertForbidden();
        $this->actingAs(User::factory()->create());
        Livewire::test(AccessApprovals::class)->call('approvePartner', $convite->id)->assertForbidden();
        Mail::assertNothingQueued();

        // O dono aprova: agora o convite vale e o e-mail vai ao convidado.
        $this->actingAs($this->cliente);
        Livewire::test(AccessApprovals::class)->assertSee('Helen')->call('approvePartner', $convite->id)->assertHasNoErrors();

        Mail::assertQueued(PartnerInviteMail::class);
        $this->assertNotNull($convite->refresh()->owner_approved_at);
    }

    public function test_o_dono_pode_recusar_e_nada_sai(): void
    {
        Mail::fake();
        app(PartnerInviteService::class)->send($this->perfil, $this->consultor, 'Helen', 'helen@exemplo.com');
        $convite = PartnerInvite::query()->sole();

        $this->actingAs($this->cliente);
        Livewire::test(AccessApprovals::class)->call('rejectPartner', $convite->id);

        Mail::assertNothingQueued();
        $this->assertNull(PartnerInvite::findValid('x'));
        $this->assertSame('expired', $convite->refresh()->status->value);
    }

    public function test_cliente_que_ainda_nao_ativou_nao_recebe_pedido_do_consultor(): void
    {
        $this->cliente->forceFill(['password_pending' => true])->save();

        $this->expectException(RuntimeException::class);
        app(PartnerInviteService::class)->send($this->perfil->refresh(), $this->consultor, 'Helen', 'helen@exemplo.com');
    }

    public function test_o_dono_que_convida_o_proprio_conjuge_continua_com_o_link_e_o_email_na_hora(): void
    {
        Mail::fake();

        $link = app(PartnerInviteService::class)->send($this->perfil, $this->cliente, 'Helen', 'helen@exemplo.com');

        $this->assertNotEmpty($link);
        Mail::assertQueued(PartnerInviteMail::class);
        $this->assertNotNull(PartnerInvite::query()->sole()->owner_approved_at);
    }

    // ------------------------------------------------------------------ familiar com saúde montado pelo consultor

    public function test_acesso_de_familiar_do_consultor_so_vale_depois_da_aprovacao_do_dono(): void
    {
        Mail::fake();
        Notification::fake();

        app(CaregiverService::class)->assistedGrant($this->perfil, $this->consultor, [$this->membro->id], 'Alias', 'alias@exemplo.com', true, CaregiverAccess::Edit, true, CaregiverGrantBasis::Representative);

        Mail::assertNothingQueued();
        Notification::assertSentTo($this->cliente, ConsultantAccessRequested::class);
        $this->assertNull(ProfileCaregiver::query()->sole()->owner_approved_at);

        // O dono recusa: o pedido vira revogado e nada sai.
        $this->actingAs($this->cliente);
        $pedido = ProfileCaregiver::query()->sole();
        Livewire::test(AccessApprovals::class)->assertSee('Alias')->call('rejectGrant', $pedido->id);

        Mail::assertNothingQueued();
        $this->assertSame(CaregiverStatus::Revoked, $pedido->refresh()->status);
    }

    public function test_aprovado_pelo_dono_o_familiar_recebe_o_convite_e_o_link_funciona(): void
    {
        Mail::fake();
        Notification::fake();
        app(CaregiverService::class)->assistedGrant($this->perfil, $this->consultor, [$this->membro->id], 'Filha', 'filha@exemplo.com', true, CaregiverAccess::Read, false, CaregiverGrantBasis::Authorized);

        $pedido = ProfileCaregiver::query()->sole();
        $this->actingAs($this->cliente);
        Livewire::test(AccessApprovals::class)->call('approveGrant', $pedido->id)->assertHasNoErrors();

        Mail::assertQueued(CaregiverInviteMail::class);
        $this->assertNotNull($pedido->refresh()->owner_approved_at);
    }

    public function test_consultor_nao_aprova_nem_enxerga_os_pedidos_como_dono(): void
    {
        app(CaregiverService::class)->assistedGrant($this->perfil, $this->consultor, [$this->membro->id], 'Alias', 'alias@exemplo.com', true, CaregiverAccess::Read, false, CaregiverGrantBasis::Authorized);
        $pedido = ProfileCaregiver::query()->sole();

        $this->actingAs($this->consultor);
        Livewire::test(AccessApprovals::class)->assertDontSee('Alias')->call('approveGrant', $pedido->id)->assertForbidden();
    }
}
