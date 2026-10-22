<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\HealthAppointmentKind;
use App\Enums\InviteStatus;
use App\Enums\MemberRole;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Care\CareIndex;
use App\Mail\CarePersonInviteMail;
use App\Models\CarePersonInvite;
use App\Models\FinancialProfile;
use App\Models\HealthAllergy;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\CareSpaceEnded;
use App\Notifications\HealthAppointmentUpcoming;
use App\Services\CarePersonService;
use App\Services\CareProfileService;
use App\Services\HealthAppointmentService;
use App\Services\HealthCardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A própria pessoa acompanhada ganha login (a mãe que decide usar o app) e passa a mandar no acesso ao espaço dela.
 */
class CarePersonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cerne.care.enabled' => true]);
    }

    public function test_o_dono_convida_e_a_pessoa_cria_o_acesso_sem_perfil_nem_assinatura(): void
    {
        Mail::fake();
        [$dono, $pessoa, $membro] = $this->dono();

        $link = app(CarePersonService::class)->invite($pessoa, $dono, 'maria@exemplo.com');
        Mail::assertQueued(CarePersonInviteMail::class, fn ($m) => $m->hasTo('maria@exemplo.com'));

        $token = basename($link);
        $this->get(route('care-person-invite.accept', $token))->assertOk()->assertSee('Maria Souza');

        $this->post(route('care-person-invite.store', $token), ['password' => 'Senha1234', 'password_confirmation' => 'Senha1234'])->assertSessionHasErrors('terms');

        $this->post(route('care-person-invite.store', $token), ['password' => 'Senha1234', 'password_confirmation' => 'Senha1234', 'terms' => '1'])
            ->assertRedirect(route('care.index'));

        $maria = User::query()->where('email', 'maria@exemplo.com')->sole();
        $this->assertAuthenticatedAs($maria);
        $this->assertNotNull($maria->email_verified_at);
        $this->assertSame(0, Subscription::query()->where('user_id', $maria->id)->count());
        $this->assertFalse(FinancialProfile::query()->where('owner_user_id', $maria->id)->exists());

        $this->assertSame($maria->id, $membro->fresh()->user_id);
        $this->assertSame($dono->id, FinancialProfile::withCareProfiles()->find($pessoa->id)->owner_user_id, 'o dono continua dono');

        // O consentimento passa a ser dela.
        $perfil = FinancialProfile::withCareProfiles()->find($pessoa->id);
        $this->assertSame('self', $perfil->care_consent_basis);
        $this->assertSame($maria->id, $perfil->care_consent_by_user_id);

        $this->assertSame(InviteStatus::Accepted, CarePersonInvite::query()->sole()->status);
        $this->get(route('care-person-invite.accept', $token))->assertSee('Convite indisponível');
    }

    public function test_conta_existente_precisa_entrar_com_o_email_convidado(): void
    {
        Mail::fake();
        [$dono, $pessoa, $membro] = $this->dono();
        $conta = User::factory()->create(['email' => 'maria@exemplo.com']);
        $outra = User::factory()->create(['email' => 'outra@exemplo.com']);

        $token = basename(app(CarePersonService::class)->invite($pessoa, $dono, 'maria@exemplo.com'));

        $this->get(route('care-person-invite.accept', $token))->assertSee('Entrar para aceitar');
        $this->actingAs($outra)->post(route('care-person-invite.store', $token))->assertSessionHasErrors('password');
        $this->assertNull($membro->fresh()->user_id);

        $this->actingAs($conta)->post(route('care-person-invite.store', $token))->assertRedirect(route('care.index'));
        $this->assertSame($conta->id, $membro->fresh()->user_id);
    }

    public function test_so_o_dono_convida_e_nao_a_si_mesmo(): void
    {
        [$dono, $pessoa] = $this->dono();
        $outro = User::factory()->create();

        try {
            app(CarePersonService::class)->invite($pessoa, $outro, 'x@exemplo.com');
            $this->fail('Só o dono convida.');
        } catch (RuntimeException) {
        }

        $this->expectExceptionMessage('própria pessoa');
        app(CarePersonService::class)->invite($pessoa, $dono, $dono->email);
    }

    public function test_a_pessoa_com_login_entra_direto_no_espaco_dela_e_so_abre_saude_e_documentos(): void
    {
        [$dono, $pessoa, $membro] = $this->dono();
        $maria = $this->virarUsuaria($pessoa, $membro);

        config(['billing.enforce_access' => true]);

        // Sem perfil financeiro: o painel leva à lista, e o espaço dela abre sem assinatura própria.
        $this->actingAs($maria)->get(route('dashboard'))->assertRedirect(route('care.index'));
        $this->get(route('care.index'))->assertOk()->assertSee('Meu espaço de saúde')->assertSee('Quem tem acesso');
        $this->post(route('care.open', $pessoa->id))->assertRedirect(route('health.card.index'));
        $this->get(route('health.card.index'))->assertOk()->assertSee('seu espaço de saúde');
        $this->get(route('cashflow.index'))->assertRedirect(route('care.index'));
        $this->get(route('health.gym.index'))->assertRedirect(route('care.index'));

        // Ela é MEMBRO (escreve), não cuidadora.
        app(HealthCardService::class)->addAllergy($membro, 'Dipirona', null);
        $this->assertSame(1, HealthAllergy::query()->count());
        $this->assertFalse(app(ProfileContext::class)->isCaregiver());
    }

    public function test_ela_ve_quem_tem_acesso_e_encerra_o_acesso_de_um_cuidador(): void
    {
        [$dono, $pessoa, $membro] = $this->dono();
        $maria = $this->virarUsuaria($pessoa, $membro);
        $filho = User::factory()->create(['name' => 'Filho Paulo']);
        $vinculo = ProfileCaregiver::query()->create(['profile_id' => $pessoa->id, 'user_id' => $filho->id, 'invited_email' => $filho->email, 'invited_name' => 'Filho Paulo', 'access_level' => CaregiverAccess::Edit, 'status' => CaregiverStatus::Active]);

        $this->actingAs($maria);
        Livewire::test(CareIndex::class)->assertSee('Filho Paulo')->assertSee($dono->name)->call('revokeAsPerson', $vinculo->id);

        $this->assertSame(CaregiverStatus::Revoked, $vinculo->fresh()->status);
    }

    public function test_quem_nao_e_a_pessoa_nao_mexe_no_acesso_dela(): void
    {
        [$dono, $pessoa, $membro] = $this->dono();
        $this->virarUsuaria($pessoa, $membro);
        $estranho = User::factory()->create();
        $vinculo = ProfileCaregiver::query()->create(['profile_id' => $pessoa->id, 'user_id' => $estranho->id, 'invited_email' => $estranho->email, 'invited_name' => 'Z', 'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active]);

        $this->actingAs($estranho);

        foreach (['revokeAsPerson' => [$vinculo->id], 'revokeMyConsent' => [$pessoa->id], 'endMySpace' => [$pessoa->id]] as $acao => $args) {
            try {
                Livewire::test(CareIndex::class)->call($acao, ...$args);
                $this->fail("$acao não pode ser feito por quem não é a pessoa.");
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            }
        }

        $this->assertSame(CaregiverStatus::Active, $vinculo->fresh()->status);
        $this->assertNotNull(FinancialProfile::withCareProfiles()->find($pessoa->id));
    }

    public function test_revogar_o_consentimento_fecha_a_saude_e_os_avisos_e_ela_pode_dar_de_novo(): void
    {
        Notification::fake();
        [$dono, $pessoa, $membro] = $this->dono();
        $maria = $this->virarUsuaria($pessoa, $membro);

        $this->actingAs($maria);
        Livewire::test(CareIndex::class)->call('revokeMyConsent', $pessoa->id);

        $this->assertNull(FinancialProfile::withCareProfiles()->find($pessoa->id)->care_consent_at);
        $this->post(route('care.open', $pessoa->id));
        $this->get(route('health.card.index'))->assertRedirect(route('care.index'));

        app(ProfileContext::class)->set($pessoa, $membro);
        $this->actingAs($dono);
        app(HealthAppointmentService::class)->create($membro, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Exame', 'scheduled_at' => CarbonImmutable::now()->addDay()], null);
        app(ProfileContext::class)->clear();
        $this->assertSame(0, app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1));

        $this->actingAs($maria);
        Livewire::test(CareIndex::class)->call('grantMyConsent', $pessoa->id);
        $this->assertNotNull(FinancialProfile::withCareProfiles()->find($pessoa->id)->care_consent_at);
        $this->assertSame('self', FinancialProfile::withCareProfiles()->find($pessoa->id)->care_consent_basis);
    }

    public function test_os_avisos_chegam_tambem_a_propria_pessoa(): void
    {
        Notification::fake();
        [$dono, $pessoa, $membro] = $this->dono();
        $maria = $this->virarUsuaria($pessoa, $membro);

        app(ProfileContext::class)->set($pessoa, $membro);
        $this->actingAs($dono);
        app(HealthAppointmentService::class)->create($membro, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Cardio', 'scheduled_at' => CarbonImmutable::now()->addDay()], null);
        app(ProfileContext::class)->clear();

        app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1);

        Notification::assertSentTo($dono, HealthAppointmentUpcoming::class);
        Notification::assertSentTo($maria, HealthAppointmentUpcoming::class);
    }

    public function test_ela_encerra_o_espaco_dados_e_arquivos_somem_e_o_dono_e_avisado_e_deixa_de_pagar(): void
    {
        Notification::fake();
        Storage::fake('local');
        [$dono, $pessoa, $membro, $assinatura] = $this->dono();
        $maria = $this->virarUsuaria($pessoa, $membro);

        app(ProfileContext::class)->set($pessoa, $membro);
        $this->actingAs($maria);
        Storage::disk('local')->put('documentos-pessoais/laudo.pdf', 'x');
        \App\Models\Document::create(['category' => 'other', 'title' => 'Laudo', 'original_filename' => 'laudo.pdf', 'storage_path' => 'documentos-pessoais/laudo.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
        app(ProfileContext::class)->clear();

        $this->assertSame('34.80', $assinatura->fresh()->monthlyPrice());

        Livewire::test(CareIndex::class)->call('endMySpace', $pessoa->id);

        $this->assertNull(FinancialProfile::withCareProfiles()->find($pessoa->id));
        Storage::disk('local')->assertMissing('documentos-pessoais/laudo.pdf');
        Notification::assertSentTo($dono, CareSpaceEnded::class);
        $this->assertSame('29.90', $assinatura->fresh()->monthlyPrice());
        $this->assertNotNull(User::query()->find($maria->id), 'a conta dela continua; só o espaço foi apagado');
    }

    public function test_a_copia_dos_dados_dela_inclui_o_espaco_de_saude(): void
    {
        Storage::fake('local');
        [$dono, $pessoa, $membro] = $this->dono();
        $maria = $this->virarUsuaria($pessoa, $membro);

        app(ProfileContext::class)->set($pessoa, $membro);
        $this->actingAs($maria);
        app(HealthCardService::class)->addAllergy($membro, 'Dipirona', null);
        app(ProfileContext::class)->clear();

        $pedido = app(\App\Services\Privacy\UserDataExporter::class)->export($maria);

        $zip = new \ZipArchive;
        $zip->open(Storage::disk('local')->path($pedido->path));
        $alergias = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with($zip->getNameIndex($i), 'healthallergy.json')) {
                $alergias = $zip->getFromIndex($i);
            }
        }
        $zip->close();

        $this->assertStringContainsString('Dipirona', $alergias);
    }

    public function test_o_painel_do_dono_convida_a_pessoa(): void
    {
        Mail::fake();
        [$dono, $pessoa] = $this->dono();
        $this->actingAs($dono);

        Livewire::test(CareIndex::class)
            ->call('manage', $pessoa->id)
            ->set('selfEmail', 'maria@exemplo.com')->call('inviteSelf')
            ->assertHasNoErrors();

        Mail::assertQueued(CarePersonInviteMail::class);
        $this->assertSame(1, CarePersonInvite::query()->count());
    }

    // ----------------------------------------------------------------------

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileMember, 3: Subscription} */
    private function dono(): array
    {
        $dono = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $dono->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $dono->id, 'role' => MemberRole::Primary]);

        $assinatura = Subscription::create([
            'user_id' => $dono->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $pessoa = app(CareProfileService::class)->create($dono, 'Maria Souza', null, CareProfileService::BASIS_AUTHORIZED);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();

        return [$dono, $pessoa, $membro, $assinatura];
    }

    private function virarUsuaria(FinancialProfile $pessoa, ProfileMember $membro): User
    {
        $maria = User::factory()->create(['email' => 'maria'.uniqid().'@exemplo.com', 'name' => 'Maria Souza']);
        $membro->update(['user_id' => $maria->id]);

        return $maria;
    }
}
