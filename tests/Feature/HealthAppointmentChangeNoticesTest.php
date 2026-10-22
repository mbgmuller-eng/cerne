<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\HealthAppointmentStatus;
use App\Enums\MemberRole;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\HealthAppointmentChanged;
use App\Notifications\HealthEscortAssigned;
use App\Services\CareProfileService;
use App\Services\HealthAppointmentService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Aviso a todos que acompanham a pessoa quando a consulta ou o exame muda: agendada, remarcada, alterada, cancelada, realizada (ou não),
 * removida e quem vai levar. Quem fez a mudança não recebe o próprio aviso.
 */
class HealthAppointmentChangeNoticesTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;

    private FinancialProfile $pessoa;

    private ProfileMember $membro;

    private User $editor;

    private User $leitor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cerne.care.enabled' => true]);

        $this->dono = User::factory()->create(['name' => 'Ana Dona']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->dono->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $this->dono->id, 'role' => MemberRole::Primary]);
        Subscription::create([
            'user_id' => $this->dono->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $this->pessoa = app(CareProfileService::class)->create($this->dono, 'Maria Souza', null, CareProfileService::BASIS_AUTHORIZED);
        $this->membro = ProfileMember::query()->where('profile_id', $this->pessoa->id)->sole();
        $this->editor = $this->cuidador('Irmão Paulo', CaregiverAccess::Edit);
        $this->leitor = $this->cuidador('Tia Ana', CaregiverAccess::Read);
    }

    public function test_agendar_avisa_os_outros_e_nao_quem_agendou(): void
    {
        Notification::fake();
        $this->como($this->editor);

        $this->consulta('Cardiologista', now()->addDays(4));

        Notification::assertSentTo($this->dono, HealthAppointmentChanged::class, fn ($n) => $n->event === 'created' && str_contains($n->message, 'Irmão Paulo agendou Cardiologista de Maria Souza'));
        Notification::assertSentTo($this->leitor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'created');
        Notification::assertNotSentTo($this->editor, HealthAppointmentChanged::class);
    }

    public function test_registro_de_consulta_que_ja_passou_nao_avisa_ninguem(): void
    {
        Notification::fake();
        $this->como($this->editor);

        $this->consulta('Antiga', now()->subDays(10));

        Notification::assertNothingSent();
    }

    public function test_remarcar_diz_de_quando_para_quando_e_salvar_sem_mudar_nada_nao_avisa(): void
    {
        Notification::fake();
        $this->como($this->dono);
        $consulta = $this->consulta('Retorno', CarbonImmutable::now()->addDays(5)->setTime(9, 0));
        Notification::fake();

        $service = app(HealthAppointmentService::class);
        $service->update($consulta, ['kind' => 'consultation', 'title' => 'Retorno', 'scheduled_at' => CarbonImmutable::now()->addDays(5)->setTime(9, 0)]);
        Notification::assertNothingSent();

        // Só a anotação muda: não é novidade para quem acompanha.
        $service->update($consulta->fresh(), ['kind' => 'consultation', 'title' => 'Retorno', 'scheduled_at' => $consulta->scheduled_at, 'notes' => 'Levar exames']);
        Notification::assertNothingSent();

        $novaData = CarbonImmutable::now()->addDays(8)->setTime(15, 30);
        $service->update($consulta->fresh(), ['kind' => 'consultation', 'title' => 'Retorno', 'scheduled_at' => $novaData]);

        Notification::assertSentTo($this->editor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'rescheduled'
            && str_contains($n->message, 'Ana Dona remarcou Retorno de Maria Souza')
            && str_contains($n->message, $novaData->format('d/m \à\s H:i')));
        Notification::assertNotSentTo($this->dono, HealthAppointmentChanged::class);
    }

    public function test_mudar_local_ou_profissional_avisa_o_que_mudou(): void
    {
        Notification::fake();
        $this->como($this->dono);
        $consulta = $this->consulta('Ortopedia', now()->addDays(3), ['location' => 'Clínica A']);
        Notification::fake();

        app(HealthAppointmentService::class)->update($consulta, ['kind' => 'consultation', 'title' => 'Ortopedia', 'scheduled_at' => $consulta->scheduled_at, 'location' => 'Clínica B', 'professional_name' => 'Dr. João']);

        Notification::assertSentTo($this->editor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'updated' && str_contains($n->message, 'profissional') && str_contains($n->message, 'local'));
    }

    public function test_cancelada_realizada_nao_realizada_e_reaberta_avisam_todos(): void
    {
        $this->como($this->editor);
        $consulta = $this->consulta('Exame de vista', now()->addDays(2));
        $service = app(HealthAppointmentService::class);

        Notification::fake();
        $service->setStatus($consulta, HealthAppointmentStatus::Cancelled, $this->editor);
        Notification::assertSentTo($this->dono, HealthAppointmentChanged::class, fn ($n) => $n->event === 'cancelled' && str_contains($n->message, 'Irmão Paulo cancelou Exame de vista'));
        Notification::assertSentTo($this->leitor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'cancelled');
        Notification::assertNotSentTo($this->editor, HealthAppointmentChanged::class);

        Notification::fake();
        $service->setStatus($consulta->fresh(), HealthAppointmentStatus::Scheduled, $this->editor);
        Notification::assertSentTo($this->dono, HealthAppointmentChanged::class, fn ($n) => $n->event === 'reopened');

        // Depois da data: realizada e não realizada.
        $passada = $this->consulta('Retorno antigo', now()->subDay());
        Notification::fake();
        $service->setStatus($passada, HealthAppointmentStatus::Done, $this->editor);
        Notification::assertSentTo($this->dono, HealthAppointmentChanged::class, fn ($n) => $n->event === 'done' && str_contains($n->message, 'foi realizada'));

        Notification::fake();
        $service->setStatus($passada->fresh(), HealthAppointmentStatus::NotDone, $this->editor);
        Notification::assertSentTo($this->leitor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'not_done' && str_contains($n->message, 'NÃO foi realizada'));
    }

    public function test_quem_leva_todos_sabem_e_o_escolhido_recebe_tambem_o_aviso_proprio(): void
    {
        $this->como($this->dono);
        $consulta = $this->consulta('Fisioterapia', now()->addDays(2));
        $service = app(HealthAppointmentService::class);

        // "Eu levo": os outros sabem.
        Notification::fake();
        $this->como($this->editor);
        $service->volunteerEscort($consulta, $this->editor);
        Notification::assertSentTo($this->dono, HealthAppointmentChanged::class, fn ($n) => $n->event === 'escort_assigned' && str_contains($n->message, 'Irmão Paulo vai levar Maria Souza'));
        Notification::assertSentTo($this->leitor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'escort_assigned');
        Notification::assertNotSentTo($this->editor, HealthAppointmentChanged::class);

        // O dono escolhe a Tia: ela recebe o aviso próprio (e não o geral); os outros recebem o geral.
        Notification::fake();
        $this->como($this->dono);
        $service->assignEscort($consulta->fresh(), $this->leitor, $this->dono);
        Notification::assertSentTo($this->leitor, HealthEscortAssigned::class);
        Notification::assertNotSentTo($this->leitor, HealthAppointmentChanged::class);
        Notification::assertSentTo($this->editor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'escort_assigned' && str_contains($n->message, 'Tia Ana vai levar'));

        // Ela desiste: todos sabem que ninguém está levando.
        Notification::fake();
        $this->como($this->leitor);
        $service->releaseEscort($consulta->fresh(), $this->leitor);
        Notification::assertSentTo($this->dono, HealthAppointmentChanged::class, fn ($n) => $n->event === 'escort_released' && str_contains($n->message, 'Tia Ana não vai mais levar'));
        Notification::assertSentTo($this->editor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'escort_released');
    }

    public function test_remover_so_avisa_se_ainda_ia_acontecer(): void
    {
        $this->como($this->dono);
        $futura = $this->consulta('Futura', now()->addDays(3));
        $passada = $this->consulta('Passada', now()->subDays(3));
        $service = app(HealthAppointmentService::class);

        Notification::fake();
        $service->delete($passada);
        Notification::assertNothingSent();

        $service->delete($futura);
        Notification::assertSentTo($this->editor, HealthAppointmentChanged::class, fn ($n) => $n->event === 'removed' && str_contains($n->message, 'Ana Dona removeu Futura'));
    }

    public function test_cada_cuidador_so_recebe_se_ligou_os_avisos_de_consultas(): void
    {
        $this->como($this->dono);
        ProfileCaregiver::query()->where('user_id', $this->leitor->id)->update(['notify_reminders' => false]);

        Notification::fake();
        $this->consulta('Nova', now()->addDays(2));

        Notification::assertSentTo($this->editor, HealthAppointmentChanged::class);
        Notification::assertNotSentTo($this->leitor, HealthAppointmentChanged::class);
    }

    public function test_num_casal_avisa_a_pessoa_e_quem_a_acompanha_mas_nao_o_conjuge(): void
    {
        Notification::fake();
        $marcelo = User::factory()->create(['name' => 'Marcelo']);
        $helena = User::factory()->create(['name' => 'Helena']);
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $marcelo->id]);
        $membroMarcelo = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $marcelo->id, 'name' => 'Marcelo']);
        ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $helena->id, 'name' => 'Helena']);
        $paulo = User::factory()->create(['name' => 'Paulo']);
        ProfileCaregiver::query()->create(['profile_id' => $perfil->id, 'member_id' => $membroMarcelo->id, 'user_id' => $paulo->id, 'invited_email' => $paulo->email, 'invited_name' => 'Paulo', 'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active]);

        // Helena cancela a consulta do Marcelo: ele e quem o acompanha sabem.
        app(ProfileContext::class)->set($perfil, $membroMarcelo);
        $this->actingAs($helena);
        $consulta = app(HealthAppointmentService::class)->create($membroMarcelo, ['kind' => 'consultation', 'title' => 'Cardio', 'scheduled_at' => now()->addDays(3)], $membroMarcelo);

        Notification::assertSentTo($marcelo, HealthAppointmentChanged::class, fn ($n) => $n->event === 'created');
        Notification::assertSentTo($paulo, HealthAppointmentChanged::class);
        Notification::assertNotSentTo($helena, HealthAppointmentChanged::class);

        // Marcelo agenda o que é dele: o Paulo sabe; a Helena, que divide o perfil, não é avisada (e ele mesmo não recebe o próprio).
        Notification::fake();
        $this->actingAs($marcelo);
        app(HealthAppointmentService::class)->create($membroMarcelo, ['kind' => 'exam', 'title' => 'Hemograma', 'scheduled_at' => now()->addDays(5)], $membroMarcelo);
        Notification::assertSentTo($paulo, HealthAppointmentChanged::class);
        Notification::assertNotSentTo($helena, HealthAppointmentChanged::class);
        Notification::assertNotSentTo($marcelo, HealthAppointmentChanged::class);
        $this->assertNotNull($consulta);
    }

    public function test_sem_o_consentimento_de_saude_de_quem_e_acompanhado_ninguem_e_avisado(): void
    {
        $marcelo = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $marcelo->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $marcelo->id]);
        $paulo = User::factory()->create();
        ProfileCaregiver::query()->create(['profile_id' => $perfil->id, 'member_id' => $membro->id, 'user_id' => $paulo->id, 'invited_email' => $paulo->email, 'invited_name' => 'Paulo', 'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active]);
        $marcelo->revokeHealthConsent();

        Notification::fake();
        $outro = User::factory()->create();
        app(ProfileContext::class)->set($perfil, $membro);
        $this->actingAs($outro);
        app(HealthAppointmentService::class)->create($membro, ['kind' => 'consultation', 'title' => 'X', 'scheduled_at' => now()->addDays(2)], $membro);

        Notification::assertNothingSent();
    }

    // ----------------------------------------------------------------------

    private function cuidador(string $nome, CaregiverAccess $nivel): User
    {
        $user = User::factory()->create(['name' => $nome]);
        ProfileCaregiver::query()->create([
            'profile_id' => $this->pessoa->id, 'member_id' => $this->membro->id, 'user_id' => $user->id, 'invited_email' => $user->email,
            'invited_name' => $nome, 'access_level' => $nivel, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
        ]);

        return $user;
    }

    /** Monta o contexto da pessoa acompanhada como quem está agindo (dono ou cuidador). */
    private function como(User $usuario): void
    {
        $this->actingAs($usuario);
        $cuidador = ProfileCaregiver::query()->active()->where('profile_id', $this->pessoa->id)->where('user_id', $usuario->id)->first();
        app(ProfileContext::class)->set($this->pessoa, null, false, $cuidador);
    }

    /** @param  array<string, mixed>  $extra */
    private function consulta(string $titulo, \DateTimeInterface $quando, array $extra = []): HealthAppointment
    {
        return app(HealthAppointmentService::class)->create($this->membro, ['kind' => 'consultation', 'title' => $titulo, 'scheduled_at' => $quando] + $extra, null);
    }
}
