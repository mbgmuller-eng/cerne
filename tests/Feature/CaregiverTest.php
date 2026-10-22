<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\HealthAppointmentKind;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Care\CareIndex;
use App\Mail\CaregiverInviteMail;
use App\Models\BankAccount;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\BelongsToProfileOrShared;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\HealthAllergy;
use App\Models\HealthAppointment;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\HealthAppointmentUpcoming;
use App\Services\CaregiverService;
use App\Services\CareProfileService;
use App\Services\HealthAppointmentService;
use App\Services\HealthCardService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pessoa acompanhada e cuidadores: um perfil de cuidado (só saúde e documentos), com cuidadores de leitura ou edição que não veem mais nada.
 */
class CaregiverTest extends TestCase
{
    use RefreshDatabase;

    /** Os únicos models que o cuidador enxerga. Mudar esta lista é uma decisão de privacidade: revise antes. */
    private const CAREGIVER_VISIBLE = [
        'HealthAllergy', 'HealthAppointment', 'HealthCard', 'HealthCareItem', 'HealthCondition', 'HealthMedication', 'HealthMedicationChange', 'Document',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cerne.care.enabled' => true]);
    }

    // --- Cadastro da pessoa -------------------------------------------------

    public function test_cadastro_exige_recurso_ligado_assinatura_com_saude_e_a_declaracao(): void
    {
        $dono = $this->dono();

        config(['cerne.care.enabled' => false]);
        $this->assertFalse(app(CareProfileService::class)->canCreate($dono));

        config(['cerne.care.enabled' => true]);
        $semPlano = User::factory()->create();
        $this->assertFalse(app(CareProfileService::class)->canCreate($semPlano));

        $this->expectExceptionMessage('autorizou');
        app(CareProfileService::class)->create($dono, 'Maria', null, 'qualquer');
    }

    public function test_pessoa_acompanhada_nasce_sem_login_com_declaracao_e_fica_escondida_do_resto_do_app(): void
    {
        $dono = $this->dono();
        $pessoa = app(CareProfileService::class)->create($dono, 'Maria Souza', '1950-03-10', CareProfileService::BASIS_LEGAL_REPRESENTATIVE);

        $this->assertSame(ProfileType::Care, $pessoa->profile_type);
        $this->assertSame($dono->id, $pessoa->owner_user_id);
        $this->assertNotNull($pessoa->care_consent_at);
        $this->assertSame('legal_representative', $pessoa->care_consent_basis);
        $this->assertSame($dono->id, $pessoa->care_consent_by_user_id);

        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();
        $this->assertNull($membro->user_id);
        $this->assertSame(MemberRole::Primary, $membro->role);

        // Esconde de todo código antigo que acha perfil por dono: carteira do consultor, perfil padrão ao entrar, etc.
        $this->assertSame(1, FinancialProfile::query()->where('owner_user_id', $dono->id)->count());
        $this->assertFalse($dono->ownedProfiles()->get()->contains('id', $pessoa->id));
        $this->assertTrue($dono->careProfiles()->get()->contains('id', $pessoa->id));
    }

    public function test_consultor_vinculado_ao_dono_nao_abre_a_pessoa_acompanhada(): void
    {
        $dono = $this->dono();
        $pessoa = $this->pessoa($dono);
        $consultor = User::factory()->consultant()->create();
        \App\Models\ConsultantClient::query()->create(['consultant_id' => $consultor->id, 'client_id' => $dono->id, 'status' => \App\Enums\ConsultantClientStatus::Active, 'invited_at' => now(), 'accepted_at' => now()]);

        $this->assertFalse($consultor->can('view', $pessoa));
        $this->assertTrue($dono->can('view', $pessoa));
    }

    // --- Convite -----------------------------------------------------------------

    public function test_convite_cria_conta_sem_perfil_nem_assinatura_e_entra_na_pessoa(): void
    {
        Mail::fake();
        $dono = $this->dono();
        $pessoa = $this->pessoa($dono);

        $link = app(CaregiverService::class)->invite($pessoa, $dono, 'Irmão Souza', 'irmao@exemplo.com', CaregiverAccess::Edit);
        Mail::assertQueued(CaregiverInviteMail::class, fn ($m) => $m->hasTo('irmao@exemplo.com'));

        $token = basename($link);
        $this->get(route('caregiver-invite.accept', $token))->assertOk()->assertSee('Irmão Souza');

        $this->post(route('caregiver-invite.store', $token), ['password' => 'Senha1234', 'password_confirmation' => 'Senha1234'])->assertSessionHasErrors('terms');

        $this->post(route('caregiver-invite.store', $token), ['password' => 'Senha1234', 'password_confirmation' => 'Senha1234', 'terms' => '1'])
            ->assertRedirect(route('care.index'));

        $irmao = User::query()->where('email', 'irmao@exemplo.com')->sole();
        $this->assertAuthenticatedAs($irmao);
        $this->assertNotNull($irmao->email_verified_at);
        $this->assertFalse(FinancialProfile::query()->where('owner_user_id', $irmao->id)->exists());
        $this->assertSame(0, Subscription::query()->where('user_id', $irmao->id)->count());

        $vinculo = ProfileCaregiver::query()->sole();
        $this->assertSame(CaregiverStatus::Active, $vinculo->status);
        $this->assertSame($irmao->id, $vinculo->user_id);
        $this->assertNull($vinculo->invite_token);

        // O link não vale duas vezes.
        $this->get(route('caregiver-invite.accept', $token))->assertSee('Convite indisponível');
    }

    public function test_quem_ja_tem_conta_precisa_entrar_com_o_email_convidado(): void
    {
        Mail::fake();
        $dono = $this->dono();
        $pessoa = $this->pessoa($dono);
        $conta = User::factory()->create(['email' => 'ja@exemplo.com']);
        $outro = User::factory()->create(['email' => 'outro@exemplo.com']);

        $token = basename(app(CaregiverService::class)->invite($pessoa, $dono, 'Já Tem', 'ja@exemplo.com', CaregiverAccess::Read));

        $this->get(route('caregiver-invite.accept', $token))->assertSee('Entrar para aceitar');

        $this->actingAs($outro)->get(route('caregiver-invite.accept', $token))->assertSee('outra conta');
        $this->actingAs($outro)->post(route('caregiver-invite.store', $token))->assertSessionHasErrors('password');
        $this->assertSame(CaregiverStatus::Invited, ProfileCaregiver::query()->sole()->status);

        $this->actingAs($conta)->post(route('caregiver-invite.store', $token))->assertRedirect(route('care.index'));
        $this->assertSame(CaregiverStatus::Active, ProfileCaregiver::query()->sole()->status);
    }

    // --- Isolamento: o que o cuidador enxerga ----------------------------------------

    public function test_cuidador_so_enxerga_os_models_da_lista_de_permitidos(): void
    {
        [, $pessoa, $contexto, $cuidador] = $this->comCuidador(CaregiverAccess::Read);
        $this->entrarComoCuidador($cuidador, $pessoa);

        $visiveis = [];
        $fechados = [];

        foreach (glob(app_path('Models/*.php')) as $arquivo) {
            $classe = 'App\\Models\\'.pathinfo($arquivo, PATHINFO_FILENAME);

            if (! is_subclass_of($classe, Model::class)) {
                continue;
            }

            $usos = class_uses_recursive($classe);

            if (! in_array(BelongsToProfile::class, $usos, true) && ! in_array(BelongsToProfileOrShared::class, $usos, true)) {
                continue;
            }

            $sql = $classe::query()->toRawSql();

            if (str_contains($sql, '1 = 0')) {
                $fechados[] = class_basename($classe);
            } else {
                $visiveis[] = class_basename($classe);
            }
        }

        sort($visiveis);
        $esperado = self::CAREGIVER_VISIBLE;
        sort($esperado);

        $this->assertSame($esperado, $visiveis, 'A lista do que o cuidador enxerga mudou: isso é decisão de privacidade, revise antes de ajustar o teste.');
        $this->assertContains('BankAccount', $fechados);
        $this->assertContains('GymPlan', $fechados);
        $this->assertContains('ExpenseCategory', $fechados);
        $this->assertTrue($contexto->isCaregiver());
    }

    public function test_cuidador_ve_saude_mas_nao_ve_financas_da_mesma_pessoa(): void
    {
        [$dono, $pessoa, $contexto, $cuidador] = $this->comCuidador(CaregiverAccess::Read);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();

        // Dado escrito pelo dono, no contexto dele.
        $contexto->set($pessoa, null);
        $this->actingAs($dono);
        app(HealthCardService::class)->addAllergy($membro, 'Dipirona', null);
        BankAccount::factory()->create(['profile_id' => $pessoa->id]);

        $this->entrarComoCuidador($cuidador, $pessoa);

        $this->assertSame(1, HealthAllergy::query()->count());
        $this->assertSame('Dipirona', HealthAllergy::query()->sole()->description);
        $this->assertSame(0, BankAccount::query()->count());
    }

    public function test_consultor_que_cuida_da_propria_mae_entra_como_cuidador_e_nao_como_consultor(): void
    {
        $dono = $this->dono();
        $pessoa = $this->pessoa($dono);
        $consultor = User::factory()->consultant()->create();
        $this->vincular($pessoa, $consultor, CaregiverAccess::Read);

        $this->actingAs($consultor)->post(route('care.open', $pessoa->id))->assertRedirect(route('health.card.index'));
        $this->get(route('health.card.index'))->assertOk();

        $contexto = app(ProfileContext::class);
        $this->assertTrue($contexto->isCaregiver());
        $this->assertFalse($contexto->isConsultant());
    }

    // --- Escrita ----------------------------------------------------------------------

    public function test_cuidador_de_leitura_nao_grava_nada(): void
    {
        [, $pessoa, , $cuidador] = $this->comCuidador(CaregiverAccess::Read);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();
        $this->entrarComoCuidador($cuidador, $pessoa);

        $this->expectException(AuthorizationException::class);
        app(HealthCardService::class)->addAllergy($membro, 'Penicilina', null);
    }

    public function test_cuidador_de_edicao_grava_com_autoria_e_so_apaga_o_que_ele_registrou(): void
    {
        [$dono, $pessoa, $contexto, $cuidador] = $this->comCuidador(CaregiverAccess::Edit);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();

        // Do dono.
        $contexto->set($pessoa, null);
        $this->actingAs($dono);
        $doDono = app(HealthCardService::class)->addAllergy($membro, 'Dipirona', null);

        $this->entrarComoCuidador($cuidador, $pessoa);
        $meu = app(HealthCardService::class)->addAllergy($membro, 'Penicilina', null);

        $this->assertSame($cuidador->id, $meu->created_by_user_id);

        $meu->delete();
        $this->assertSame(1, HealthAllergy::query()->count());

        $this->expectException(AuthorizationException::class);
        HealthAllergy::query()->findOrFail($doDono->id)->delete();
    }

    // --- Documentos --------------------------------------------------------------------

    public function test_cuidador_ve_so_o_documento_liberado_e_o_que_ele_cadastra_nasce_liberado(): void
    {
        [$dono, $pessoa, $contexto, $cuidador] = $this->comCuidador(CaregiverAccess::Edit);

        $contexto->set($pessoa, null);
        $this->actingAs($dono);
        $fechado = $this->documento($pessoa, 'Escritura', false);
        $liberado = $this->documento($pessoa, 'Receita', true);

        $this->entrarComoCuidador($cuidador, $pessoa);
        $this->assertEquals([$liberado->id], Document::query()->pluck('id')->all());

        $dele = $this->documento($pessoa, 'Exame do irmão', false);
        $this->assertTrue($dele->fresh()->shared_with_caregivers);
        $this->assertSame($cuidador->id, $dele->created_by_user_id);

        $contexto->set($pessoa, null);
        $this->actingAs($dono);
        $this->assertSame(3, Document::query()->count(), 'o dono vê tudo');
        $this->assertNotNull($fechado);
    }

    // --- Rotas e acesso -------------------------------------------------------------------

    public function test_cuidador_sem_plano_e_sem_perfil_abre_so_saude_e_documentos(): void
    {
        [, $pessoa, , $cuidador] = $this->comCuidador(CaregiverAccess::Read);
        config(['billing.enforce_access' => true]);

        $this->actingAs($cuidador)->get(route('dashboard'))->assertRedirect(route('care.index'));
        $this->get(route('care.index'))->assertOk()->assertSee($pessoa->profile_name);

        $this->post(route('care.open', $pessoa->id))->assertRedirect(route('health.card.index'));

        $this->get(route('health.card.index'))->assertOk();
        $this->get(route('health.appointments.index'))->assertOk();
        $this->get(route('health.care.index'))->assertOk();
        $this->get(route('documents.vault.index'))->assertOk();

        // O resto é fechado, mesmo o que existe só para o dono.
        $this->get(route('cashflow.index'))->assertRedirect(route('care.index'));
        $this->get(route('investments.index'))->assertRedirect(route('care.index'));
        $this->get(route('health.gym.index'))->assertRedirect(route('care.index'));
        $this->get(route('my-account'))->assertRedirect(route('care.index'));
    }

    public function test_revogar_corta_o_acesso_na_hora(): void
    {
        [$dono, $pessoa, , $cuidador] = $this->comCuidador(CaregiverAccess::Read);

        $this->actingAs($cuidador)->post(route('care.open', $pessoa->id))->assertRedirect();
        $this->get(route('health.card.index'))->assertOk();

        app(CaregiverService::class)->revoke(ProfileCaregiver::query()->sole());

        $this->get(route('health.card.index'))->assertNotFound();
        $this->post(route('care.open', $pessoa->id))->assertForbidden();
        $this->assertNotNull($dono);
    }

    public function test_so_o_dono_gerencia_cuidadores_pelo_painel(): void
    {
        Mail::fake();
        [$dono, $pessoa, , $cuidador] = $this->comCuidador(CaregiverAccess::Edit);
        $vinculo = ProfileCaregiver::query()->sole();

        // Cuidador de edição não convida nem revoga.
        $this->actingAs($cuidador);
        try {
            Livewire::test(CareIndex::class)->call('revoke', $vinculo->id);
            $this->fail('Cuidador não pode revogar.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // o painel só acha o que é do próprio dono
        }
        $this->assertSame(CaregiverStatus::Active, $vinculo->fresh()->status);

        $this->actingAs($dono);
        Livewire::test(CareIndex::class)
            ->call('manage', $pessoa->id)
            ->set('inviteName', 'Tia Ana')->set('inviteEmail', 'ana@exemplo.com')->set('inviteAccess', 'read')->call('invite')
            ->assertHasNoErrors()
            ->call('changeAccess', $vinculo->id, 'read')
            ->call('revoke', $vinculo->id);

        $this->assertSame(CaregiverStatus::Revoked, $vinculo->fresh()->status);
        $this->assertSame(CaregiverAccess::Read, $vinculo->fresh()->access_level);
        Mail::assertQueued(CaregiverInviteMail::class);
    }

    public function test_cadastro_pela_tela_exige_a_declaracao(): void
    {
        $dono = $this->dono();
        $this->actingAs($dono);

        Livewire::test(CareIndex::class)->call('startAdding')->set('newName', 'Pai')->set('newBasis', 'authorized')->call('addPerson')->assertHasErrors('declared');
        $this->assertSame(0, $dono->careProfiles()->count());

        Livewire::test(CareIndex::class)->call('startAdding')->set('newName', 'Pai')->set('newBasis', 'authorized')->set('declared', true)->call('addPerson')->assertHasNoErrors();
        $this->assertSame(1, $dono->careProfiles()->count());
    }

    public function test_qr_e_agenda_do_cuidador(): void
    {
        [$dono, $pessoa, $contexto, $leitor] = $this->comCuidador(CaregiverAccess::Read);
        $editor = User::factory()->create();
        $this->vincular($pessoa, $editor, CaregiverAccess::Edit);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();

        // Leitor: sem código gerado pelo dono, não há QR para ver (e a tela não grava nada ao abrir).
        $this->actingAs($leitor)->post(route('care.open', $pessoa->id));
        $this->get(route('health.qrcode.show', $membro->id))->assertNotFound();
        $this->assertNull(\App\Models\HealthCard::withoutGlobalScopes()->where('member_id', $membro->id)->first()?->emergency_token);

        // Editor gera o código ao pedir o QR (como o dono faria).
        $this->actingAs($editor)->post(route('care.open', $pessoa->id));
        $this->get(route('health.qrcode.show', $membro->id))->assertOk();

        $this->actingAs($leitor)->post(route('care.open', $pessoa->id));
        $this->get(route('health.qrcode.show', $membro->id))->assertOk();

        // O dono marca a consulta; o cuidador baixa o evento para a agenda dele.
        $contexto->set($pessoa, null);
        $this->actingAs($dono);
        $consulta = app(HealthAppointmentService::class)->create($membro, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Ortopedista', 'scheduled_at' => CarbonImmutable::now()->addDays(3)], null);

        $this->actingAs($leitor)->post(route('care.open', $pessoa->id));
        $this->get(route('health.appointments.ics', $consulta->id))->assertOk();
    }

    public function test_consultor_do_dono_nao_ve_a_pessoa_acompanhada_nem_o_aniversario_dela(): void
    {
        Notification::fake();
        $dono = $this->dono();
        $pessoa = app(CareProfileService::class)->create($dono, 'Maria Souza', CarbonImmutable::now()->addDays((int) config('cerne.notifications.days_before_important_date'))->subYears(70)->toDateString(), CareProfileService::BASIS_AUTHORIZED);
        $consultor = User::factory()->consultant()->create();
        \App\Models\ConsultantClient::query()->create(['consultant_id' => $consultor->id, 'client_id' => $dono->id, 'status' => \App\Enums\ConsultantClientStatus::Active, 'invited_at' => now(), 'accepted_at' => now()]);

        $carteira = json_encode(app(\App\Services\ConsultantPortfolioService::class)->overview($consultor));
        $this->assertStringNotContainsString('Maria Souza', (string) $carteira);

        $this->assertSame(0, app(\App\Services\ImportantDatesService::class)->notifyUpcomingBirthdays());
        Notification::assertNothingSent();
        $this->assertNotNull($pessoa);
    }

    // --- Avisos -------------------------------------------------------------------------------

    public function test_lembrete_de_consulta_vai_ao_dono_e_aos_cuidadores_com_avisos_ligados(): void
    {
        Notification::fake();
        [$dono, $pessoa, $contexto, $cuidador] = $this->comCuidador(CaregiverAccess::Read);
        $mudo = User::factory()->create();
        $this->vincular($pessoa, $mudo, CaregiverAccess::Read);
        ProfileCaregiver::query()->where('user_id', $mudo->id)->update(['notify_reminders' => false]);

        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();
        $contexto->set($pessoa, null);
        $this->actingAs($dono);
        $amanha = CarbonImmutable::now()->addDay()->setTime(10, 0);
        app(HealthAppointmentService::class)->create($membro, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Cardiologista', 'scheduled_at' => $amanha], null);
        $contexto->clear();

        $enviados = app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1);

        $this->assertSame(2, $enviados);
        Notification::assertSentTo($dono, HealthAppointmentUpcoming::class);
        Notification::assertSentTo($cuidador, HealthAppointmentUpcoming::class);
        Notification::assertNotSentTo($mudo, HealthAppointmentUpcoming::class);
    }

    public function test_sem_a_declaracao_de_consentimento_ninguem_e_avisado(): void
    {
        Notification::fake();
        [$dono, $pessoa, $contexto] = $this->comCuidador(CaregiverAccess::Read);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();

        $contexto->set($pessoa, null);
        $this->actingAs($dono);
        app(HealthAppointmentService::class)->create($membro, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Exame', 'scheduled_at' => CarbonImmutable::now()->addDay()], null);
        $contexto->clear();

        FinancialProfile::withCareProfiles()->whereKey($pessoa->id)->update(['care_consent_at' => null]);

        $this->assertSame(0, app(HealthAppointmentService::class)->notifyUpcoming(CarbonImmutable::now(), 1));
        $this->assertSame(1, HealthAppointment::withoutGlobalScopes()->count());
    }

    // --- Exclusão -----------------------------------------------------------------------------------

    public function test_excluir_a_pessoa_apaga_dados_e_arquivos_e_tira_o_acesso_dos_cuidadores(): void
    {
        Storage::fake('local');
        [$dono, $pessoa, $contexto] = $this->comCuidador(CaregiverAccess::Edit);

        $contexto->set($pessoa, null);
        $this->actingAs($dono);
        Storage::disk('local')->put('documentos-pessoais/x.pdf', 'x');
        Document::withoutGlobalScopes()->create([
            'profile_id' => $pessoa->id, 'category' => 'other', 'title' => 'Laudo', 'original_filename' => 'x.pdf',
            'storage_path' => 'documentos-pessoais/x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ]);
        $contexto->clear();

        $apagados = app(CareProfileService::class)->delete($pessoa);

        $this->assertSame(1, $apagados);
        Storage::disk('local')->assertMissing('documentos-pessoais/x.pdf');
        $this->assertNull(FinancialProfile::withCareProfiles()->find($pessoa->id));
        $this->assertSame(0, ProfileCaregiver::query()->count());
        $this->assertSame(0, ProfileMember::query()->where('profile_id', $pessoa->id)->count());
    }

    public function test_excluir_a_conta_do_dono_apaga_as_pessoas_acompanhadas(): void
    {
        Storage::fake('local');
        $dono = $this->dono();
        $pessoa = $this->pessoa($dono);

        app(\App\Services\Privacy\AccountDeletionService::class)->delete($dono);

        $this->assertNull(FinancialProfile::withCareProfiles()->find($pessoa->id));
    }

    // ------------------------------------------------------------------------------------------------

    /** Dono com assinatura direta (Completo), perfil próprio e o recurso ligado. */
    private function dono(): User
    {
        $dono = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $dono->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $dono->id, 'role' => MemberRole::Primary]);

        Subscription::create([
            'user_id' => $dono->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        return $dono;
    }

    private function pessoa(User $dono): FinancialProfile
    {
        return app(CareProfileService::class)->create($dono, 'Maria Souza', null, CareProfileService::BASIS_AUTHORIZED);
    }

    private function vincular(FinancialProfile $pessoa, User $usuario, CaregiverAccess $nivel): ProfileCaregiver
    {
        return ProfileCaregiver::query()->create([
            'profile_id' => $pessoa->id, 'user_id' => $usuario->id, 'invited_email' => $usuario->email, 'invited_name' => $usuario->name,
            'access_level' => $nivel, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: FinancialProfile, 2: ProfileContext, 3: User} dono, pessoa, contexto e o cuidador (sem perfil nem plano) */
    private function comCuidador(CaregiverAccess $nivel): array
    {
        $dono = $this->dono();
        $pessoa = $this->pessoa($dono);
        $cuidador = User::factory()->create();
        $this->vincular($pessoa, $cuidador, $nivel);

        return [$dono, $pessoa, app(ProfileContext::class), $cuidador];
    }

    /** Monta o contexto como o SetProfileContext faria para o cuidador. */
    private function entrarComoCuidador(User $cuidador, FinancialProfile $pessoa): void
    {
        $this->actingAs($cuidador);
        app(ProfileContext::class)->set($pessoa, null, false, ProfileCaregiver::query()->active()->where('profile_id', $pessoa->id)->where('user_id', $cuidador->id)->first());
    }

    private function documento(FinancialProfile $pessoa, string $titulo, bool $liberado): Document
    {
        return Document::create([
            'category' => 'other', 'title' => $titulo, 'original_filename' => 'a.pdf', 'storage_path' => 'documentos-pessoais/'.$titulo.'.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1, 'shared_with_caregivers' => $liberado,
        ]);
    }
}
