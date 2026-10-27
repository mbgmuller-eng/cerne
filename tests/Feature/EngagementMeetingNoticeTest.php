<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\EngagementMeetingFormat;
use App\Enums\EngagementMeetingStatus;
use App\Enums\MemberRole;
use App\Models\ConsultantClient;
use App\Models\EngagementMeeting;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\EngagementNotice;
use App\Services\EngagementAccess;
use App\Services\EngagementReminderService;
use App\Services\EngagementService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O aviso de reunião (lembrete do dia anterior, marcada, remarcada, cancelada) diz com quem é, quando, em que formato, onde e a pauta,
 * e fala o nome do OUTRO lado para cada destinatário.
 */
class EngagementMeetingNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $cliente;

    private FinancialProfile $perfil;

    private ProfileMember $membro;

    private User $consultor;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 09:00:00'));

        $this->cliente = User::factory()->create(['name' => 'Juliana Prado']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->cliente->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->cliente->id, 'role' => MemberRole::Primary, 'name' => 'Juliana Prado']);
        $this->consultor = User::factory()->consultant()->create(['name' => 'Marcelo Müller']);
        ConsultantClient::factory()->create(['consultant_id' => $this->consultor->id, 'client_id' => $this->cliente->id, 'status' => ConsultantClientStatus::Active, 'accepted_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_lembrete_do_dia_anterior_diz_com_quem_quando_formato_local_e_pauta(): void
    {
        Notification::fake();
        $this->reuniao();

        app(EngagementReminderService::class)->run(CarbonImmutable::parse('2026-10-26'));

        Notification::assertSentTo($this->consultor, EngagementNotice::class, function (EngagementNotice $n) {
            $mail = (string) $n->toMail($this->consultor)->render();

            return $n->toMail($this->consultor)->subject === 'Reunião amanhã com Juliana Prado'
                && str_contains($n->toArray($this->consultor)['message'], 'com Juliana Prado é amanhã, terça-feira, 27/10, às 10:30, por videochamada')
                && str_contains($n->toArray($this->consultor)['message'], 'Assuntos: Reserva de emergência; Seguro de vida')
                && str_contains($mail, 'Cliente:') && str_contains($mail, 'Juliana Prado')
                && str_contains($mail, 'Consultor:') && str_contains($mail, 'Marcelo Müller')
                && str_contains($mail, 'terça-feira, 27/10, às 10:30 (45 min)')
                && str_contains($mail, 'meet.example.com/abc')
                && str_contains($mail, 'Reserva de emergência') && str_contains($mail, 'Seguro de vida')
                && str_contains($mail, 'Levar extratos de setembro')
                && str_contains($mail, 'Adicionar à agenda');
        });

        // O cliente lê o nome do consultor, não o dele mesmo.
        Notification::assertSentTo($this->cliente, EngagementNotice::class, fn (EngagementNotice $n) => $n->toMail($this->cliente)->subject === 'Reunião amanhã com Marcelo Müller'
            && str_contains($n->toArray($this->cliente)['message'], 'com Marcelo Müller é amanhã'));
    }

    public function test_sem_pauta_o_aviso_diz_que_ainda_nao_foi_definida(): void
    {
        Notification::fake();
        $this->reuniao(['agenda' => null, 'prep_notes' => null, 'location' => null, 'format' => EngagementMeetingFormat::Phone]);

        app(EngagementReminderService::class)->run(CarbonImmutable::parse('2026-10-26'));

        Notification::assertSentTo($this->consultor, EngagementNotice::class, function (EngagementNotice $n) {
            $mail = (string) $n->toMail($this->consultor)->render();

            return str_contains($mail, 'Ainda não definida') && str_contains($mail, 'Formato:') && ! str_contains($mail, 'Local:') && ! str_contains($mail, 'O que levar')
                && str_contains($n->toArray($this->consultor)['message'], 'por telefone') && ! str_contains($n->toArray($this->consultor)['message'], 'Assuntos');
        });
    }

    public function test_marcar_remarcar_e_cancelar_levam_os_detalhes_ao_cliente(): void
    {
        Notification::fake();
        $service = app(EngagementService::class);
        $this->actingAs($this->consultor);
        app(ProfileContext::class)->set($this->perfil, null, true);
        $visitante = app(EngagementAccess::class)->viewer();

        $reuniao = $service->saveMeeting($visitante, null, [
            'scheduled_at' => CarbonImmutable::parse('2026-10-29 14:00'), 'agenda' => "Metas\nDívidas", 'format' => 'in_person', 'location' => 'Escritório, sala 3',
        ]);

        Notification::assertSentTo($this->cliente, EngagementNotice::class, function (EngagementNotice $n) {
            $mail = (string) $n->toMail($this->cliente)->render();

            return $n->kind === 'meeting_scheduled'
                && $n->toMail($this->cliente)->subject === 'Reunião marcada com Marcelo Müller'
                && str_contains($mail, 'quinta-feira, 29/10, às 14:00') && str_contains($mail, 'Escritório, sala 3') && str_contains($mail, 'Local:')
                && str_contains($mail, 'Metas') && str_contains($mail, 'Dívidas');
        });

        $service->saveMeeting($visitante, $reuniao, ['scheduled_at' => CarbonImmutable::parse('2026-10-30 09:30')]);

        Notification::assertSentTo($this->cliente, EngagementNotice::class, function (EngagementNotice $n) {
            $mail = (string) $n->toMail($this->cliente)->render();

            return $n->kind === 'meeting_rescheduled' && str_contains($mail, 'Era em:') && str_contains($mail, 'quinta-feira, 29/10, às 14:00')
                && str_contains($mail, 'sexta-feira, 30/10, às 09:30');
        });

        $service->cancelMeeting($visitante, $reuniao->fresh());

        Notification::assertSentTo($this->cliente, EngagementNotice::class, fn (EngagementNotice $n) => $n->kind === 'meeting_cancelled'
            && $n->toMail($this->cliente)->subject === 'Reunião cancelada com Marcelo Müller'
            && str_contains($n->toArray($this->cliente)['message'], 'sexta-feira, 30/10, às 09:30 foi cancelada')
            && ! str_contains((string) $n->toMail($this->cliente)->render(), 'Adicionar à agenda'));
    }

    /** @param  array<string, mixed>  $campos */
    private function reuniao(array $campos = []): EngagementMeeting
    {
        return EngagementMeeting::withoutGlobalScopes()->create(array_merge([
            'profile_id' => $this->perfil->id, 'consultant_id' => $this->consultor->id,
            'scheduled_at' => CarbonImmutable::parse('2026-10-27 10:30'), 'duration_minutes' => 45, 'status' => EngagementMeetingStatus::Scheduled,
            'format' => EngagementMeetingFormat::Video, 'location' => 'https://meet.example.com/abc',
            'agenda' => "Reserva de emergência\nSeguro de vida", 'prep_notes' => 'Levar extratos de setembro',
        ], $campos));
    }
}
