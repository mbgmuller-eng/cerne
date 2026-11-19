<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverGrantBasis;
use App\Enums\CaregiverStatus;
use App\Enums\ConsultantClientStatus;
use App\Enums\MemberRole;
use App\Livewire\Care\CareIndex;
use App\Mail\CaregiverInviteMail;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\FamilyAccessGranted;
use App\Services\CaregiverService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * O convite do familiar que acompanha a saúde e/ou as finanças: pela própria pessoa ("Quem me acompanha") e assistido pelo consultor, em nome de
 * quem não consegue fazer sozinho. Finanças são sempre leitura; o que o consultor libera fica registrado, avisa a pessoa e ela encerra quando quiser.
 */
class FamilyInviteTest extends TestCase
{
    use RefreshDatabase;

    private User $pai;

    private User $mae;

    private User $consultor;

    private FinancialProfile $perfil;

    private ProfileMember $membroPai;

    private ProfileMember $membroMae;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cerne.care.enabled' => true]);

        $this->pai = User::factory()->create(['name' => 'Dilermando']);
        $this->mae = User::factory()->create(['name' => 'Esposa']);
        $this->consultor = User::factory()->consultant()->create(['name' => 'Marcelo']);
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->pai->id, 'profile_name' => 'Casal Dilermando']);
        $this->membroPai = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->pai->id, 'role' => MemberRole::Primary, 'name' => 'Dilermando']);
        $this->membroMae = ProfileMember::factory()->secondary()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->mae->id, 'name' => 'Esposa']);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->pai->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
    }

    private function comoConsultor(): void
    {
        $this->actingAs($this->consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
    }

    // --- Convite pela própria pessoa ----------------------------------------------------------------------------------------

    public function test_a_pessoa_convida_um_filho_so_para_as_financas_e_o_acesso_nasce_de_leitura(): void
    {
        Mail::fake();

        Livewire::actingAs($this->pai)->test(CareIndex::class)
            ->set('followName', 'Filho A')->set('followEmail', 'filho@exemplo.com')
            ->set('followHealth', false)->set('followFinance', true)->set('followAccess', 'edit')
            ->call('inviteFollower', $this->membroPai->id)->assertHasNoErrors();

        $acesso = ProfileCaregiver::query()->sole();
        $this->assertFalse($acesso->can_view_health);
        $this->assertTrue($acesso->can_view_finance);
        $this->assertSame(CaregiverAccess::Read, $acesso->access_level, 'finanças são sempre leitura, mesmo se pediram edição');
        $this->assertSame($this->membroPai->id, $acesso->member_id);
        $this->assertSame(CaregiverGrantBasis::Self, $acesso->grant_basis);
        $this->assertNull($acesso->granted_by_user_id);

        Mail::assertQueued(CaregiverInviteMail::class, fn (CaregiverInviteMail $m) => $m->hasTo('filho@exemplo.com') && $m->finance === true && $m->health === false);
    }

    public function test_o_convite_exige_pelo_menos_uma_coisa_e_nao_aceita_o_conjuge(): void
    {
        Mail::fake();

        Livewire::actingAs($this->pai)->test(CareIndex::class)
            ->set('followName', 'Alguém')->set('followEmail', 'alguem@exemplo.com')->set('followHealth', false)->set('followFinance', false)
            ->call('inviteFollower', $this->membroPai->id)->assertHasErrors('followEmail');

        Livewire::actingAs($this->pai)->test(CareIndex::class)
            ->set('followName', 'Esposa')->set('followEmail', $this->mae->email)->set('followFinance', true)
            ->call('inviteFollower', $this->membroPai->id)->assertHasErrors('followEmail');

        $this->assertSame(0, ProfileCaregiver::query()->count());
    }

    public function test_so_a_propria_pessoa_convida_para_os_dados_dela(): void
    {
        Livewire::actingAs($this->mae)->test(CareIndex::class)
            ->set('followName', 'Intruso')->set('followEmail', 'x@exemplo.com')->set('followFinance', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('própria saúde');
        app(CaregiverService::class)->inviteFollower($this->membroPai, $this->mae, 'Intruso', 'x@exemplo.com', CaregiverAccess::Read, false, true);
    }

    public function test_a_pessoa_liga_e_desliga_saude_e_financas_do_acesso_e_sempre_sobra_uma(): void
    {
        $acesso = ProfileCaregiver::query()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $this->membroPai->id, 'invited_email' => 'f@exemplo.com', 'invited_name' => 'Filho',
            'access_level' => CaregiverAccess::Edit, 'status' => CaregiverStatus::Invited, 'can_view_health' => true, 'can_view_finance' => false,
        ]);

        $tela = Livewire::actingAs($this->pai)->test(CareIndex::class);

        $tela->call('toggleFollowerFinance', $acesso->id)->assertHasNoErrors();
        $this->assertTrue($acesso->fresh()->can_view_finance);

        $tela->call('toggleFollowerHealth', $acesso->id);
        $acesso->refresh();
        $this->assertFalse($acesso->can_view_health);
        $this->assertSame(CaregiverAccess::Read, $acesso->access_level, 'sem a saúde, o nível de edição não tem o que editar');

        // Tirar a última coisa não é permitido: para isso, encerra-se o acesso.
        $tela->call('toggleFollowerFinance', $acesso->id)->assertHasErrors('followEmail');
        $this->assertTrue($acesso->fresh()->can_view_finance);
    }

    public function test_o_conjuge_nao_mexe_no_acesso_dado_pelo_outro(): void
    {
        $acesso = ProfileCaregiver::query()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $this->membroPai->id, 'invited_email' => 'f@exemplo.com', 'invited_name' => 'Filho',
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'can_view_finance' => true,
        ]);

        Livewire::actingAs($this->mae)->test(CareIndex::class)->call('toggleFollowerFinance', $acesso->id)->assertNotFound();
    }

    public function test_o_limite_de_gasto_que_avisa_a_familia_e_salvo_por_quem_e_do_perfil(): void
    {
        $tela = Livewire::actingAs($this->pai)->test(CareIndex::class);

        $tela->set('alertThreshold.'.$this->perfil->id, '2500,5')->call('saveAlertThreshold', $this->perfil->id)->assertHasErrors();
        $tela->set('alertThreshold.'.$this->perfil->id, '2500.50')->call('saveAlertThreshold', $this->perfil->id)->assertHasNoErrors();
        $this->assertSame('2500.50', (string) $this->perfil->fresh()->family_alert_threshold);

        $tela->set('alertThreshold.'.$this->perfil->id, '')->call('saveAlertThreshold', $this->perfil->id);
        $this->assertNull($this->perfil->fresh()->family_alert_threshold, 'vazio desliga');

        $outro = User::factory()->create();
        Livewire::actingAs($outro)->test(CareIndex::class)->set('alertThreshold.'.$this->perfil->id, '100')->call('saveAlertThreshold', $this->perfil->id)->assertNotFound();
    }

    // --- Convite assistido pelo consultor -------------------------------------------------------------------------------------

    public function test_o_consultor_libera_o_acesso_dos_dois_com_um_so_email_um_aviso_para_cada_pessoa_e_um_aceite_ativa_os_dois(): void
    {
        Mail::fake();
        Notification::fake();
        $this->comoConsultor();

        app(CaregiverService::class)->assistedGrant(
            $this->perfil, $this->consultor, [$this->membroPai->id, $this->membroMae->id], 'Filha B', 'filha@exemplo.com', true, CaregiverAccess::Read, true, CaregiverGrantBasis::Authorized,
        );

        // O consultor só monta o pedido: nenhum e-mail sai e ninguém é avisado de "acesso liberado" antes de o dono aprovar.
        Mail::assertNothingQueued();
        Notification::assertNotSentTo($this->mae, FamilyAccessGranted::class);
        Notification::assertSentTo($this->perfil->owner, \App\Notifications\ConsultantAccessRequested::class);

        // Só o dono do perfil aprova.
        $pedido = ProfileCaregiver::query()->orderBy('member_id')->first();
        try {
            app(CaregiverService::class)->approveAssistedGrant($pedido, $this->consultor);
            $this->fail('o consultor não pode aprovar o próprio pedido');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Só o dono', $e->getMessage());
        }

        $link = app(CaregiverService::class)->approveAssistedGrant($pedido, $this->perfil->owner);

        $acessos = ProfileCaregiver::query()->orderBy('member_id')->get();
        $this->assertCount(2, $acessos);
        $this->assertSame([$this->consultor->id, $this->consultor->id], $acessos->pluck('granted_by_user_id')->all());
        $this->assertSame([CaregiverGrantBasis::Authorized, CaregiverGrantBasis::Authorized], $acessos->pluck('grant_basis')->all());
        $this->assertNotNull($acessos->first()->granted_declared_at);
        $this->assertTrue($acessos->every(fn ($a) => $a->can_view_health && $a->can_view_finance && $a->status === CaregiverStatus::Invited));

        Mail::assertQueued(CaregiverInviteMail::class, 1);
        Mail::assertQueued(CaregiverInviteMail::class, fn (CaregiverInviteMail $m) => $m->assisted && $m->personName === 'Dilermando e Esposa');
        Notification::assertSentTo($this->pai, FamilyAccessGranted::class, fn ($n) => str_contains($n->toArray($this->pai)['message'], 'Marcelo liberou o acesso de Filha B'));
        Notification::assertSentTo($this->mae, FamilyAccessGranted::class);

        // Um aceite ativa o lote inteiro.
        $this->post(route('caregiver-invite.store', basename($link)), ['password' => 'Senha1234', 'password_confirmation' => 'Senha1234', 'terms' => '1'])->assertRedirect(route('care.index'));

        $this->assertSame(2, ProfileCaregiver::query()->where('status', CaregiverStatus::Active->value)->count());
        $filha = User::query()->where('email', 'filha@exemplo.com')->sole();
        $this->assertSame(2, ProfileCaregiver::query()->where('user_id', $filha->id)->count());
    }

    public function test_so_o_consultor_vinculado_monta_o_acesso_e_a_base_e_obrigatoria(): void
    {
        $this->comoConsultor();

        try {
            app(CaregiverService::class)->assistedGrant($this->perfil, $this->consultor, [$this->membroPai->id], 'F', 'f@exemplo.com', true, CaregiverAccess::Read, true, CaregiverGrantBasis::Self);
            $this->fail('a base "a própria pessoa" não vale para o consultor');
        } catch (RuntimeException) {
        }

        $semVinculo = User::factory()->consultant()->create();
        $this->expectException(RuntimeException::class);
        app(CaregiverService::class)->assistedGrant($this->perfil, $semVinculo, [$this->membroPai->id], 'F', 'f@exemplo.com', true, CaregiverAccess::Read, true, CaregiverGrantBasis::Authorized);
    }

    public function test_o_consultor_nao_ve_nem_abre_o_acesso_da_familia_do_cliente(): void
    {
        // Decisão do dono (2026-11-19): o acesso da família é só do próprio usuário. O consultor não vê a opção no menu, não abre a tela
        // (a rota nem existe) e não enxerga quem tem acesso.
        ProfileCaregiver::query()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $this->membroPai->id, 'invited_email' => 'f@exemplo.com', 'invited_name' => 'Filha Secreta',
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'can_view_finance' => true,
        ]);

        $this->comoConsultor();

        $this->get('/acesso-da-familia')->assertNotFound();
        $this->withSession([\App\Http\Middleware\SetProfileContext::SESSION_KEY => $this->perfil->id])->get(route('cashflow.index'))->assertOk()->assertSee('Fluxo de caixa')->assertDontSee('Acesso da família')->assertDontSee('Filha Secreta');
        self::assertFalse(\Illuminate\Support\Facades\Route::has('consultant.family-access'));
    }

    public function test_o_consultor_nao_encerra_o_que_a_pessoa_liberou_sozinha(): void
    {
        $dela = ProfileCaregiver::query()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $this->membroPai->id, 'invited_email' => 'f@exemplo.com', 'invited_name' => 'Filho',
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'can_view_finance' => true,
        ]);

        $this->comoConsultor();

        $this->assertFalse($this->consultor->can('manageGrant', $dela));
        $this->assertTrue($this->pai->can('manageGrant', $dela));
        $this->assertFalse($this->mae->can('manageGrant', $dela));
    }

    public function test_o_familiar_recebe_so_os_avisos_do_que_acompanha(): void
    {
        $filho = User::factory()->create();
        $soFinancas = ProfileCaregiver::query()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $this->membroPai->id, 'user_id' => $filho->id, 'invited_email' => $filho->email, 'invited_name' => 'Filho',
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'can_view_finance' => true, 'can_view_health' => false,
        ]);

        $tela = Livewire::actingAs($filho)->test(CareIndex::class);
        $tela->assertSee('Avisos de conta a vencer ou atrasada')->assertSee('Resumo semanal das finanças')->assertSee('Avisos de gasto alto')->assertDontSee('Avisos de consultas e exames');

        $tela->call('toggleNotifyWeekly', $soFinancas->id)->call('toggleNotifyAlerts', $soFinancas->id)->call('toggleNotifyFinance', $soFinancas->id);
        $soFinancas->refresh();
        $this->assertSame([false, false, false], [$soFinancas->notify_weekly, $soFinancas->notify_alerts, $soFinancas->notify_finance]);
    }
}
