<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
use App\Models\FinancialProfile;
use App\Models\HealthAppointment;
use App\Models\ProfileMember;
use App\Models\User;
use App\Notifications\HealthAppointmentUpcoming;
use App\Services\HealthAppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lembrete de consulta/exame roda no cron, sem ProfileContext — por isso
 * o teste mais importante é o destinatário: os DONOS da conta, nunca o
 * consultor financeiro (ImportantDatesService faz o oposto de propósito).
 */
class HealthAppointmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $hoje;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hoje = CarbonImmutable::parse('2026-09-01');
    }

    public function test_notifica_um_dia_antes_por_padrao(): void
    {
        Notification::fake();
        [$perfil, $titular, $membroTitular] = $this->criarPerfil();

        $consulta = HealthAppointment::withoutGlobalScopes()->create([
            'profile_id' => $perfil->id, 'member_id' => $membroTitular->id, 'kind' => 'consultation',
            'title' => 'Cardiologista', 'scheduled_at' => $this->hoje->addDay(), 'created_by_member_id' => $membroTitular->id,
        ]);

        app(HealthAppointmentService::class)->notifyUpcoming($this->hoje);

        Notification::assertSentToTimes($titular, HealthAppointmentUpcoming::class, 1);
        Notification::assertSentTo($titular, HealthAppointmentUpcoming::class, fn ($n) => $n->appointmentId === $consulta->id);
    }

    public function test_avisa_os_dois_do_casal_nunca_o_consultor(): void
    {
        Notification::fake();
        [$perfil, $titular, $membroTitular, $conjuge, $membroConjuge] = $this->criarCasal();

        HealthAppointment::withoutGlobalScopes()->create([
            'profile_id' => $perfil->id, 'member_id' => $membroConjuge->id, 'kind' => 'exam',
            'title' => 'Hemograma', 'scheduled_at' => $this->hoje->addDay(), 'created_by_member_id' => $membroConjuge->id,
        ]);

        app(HealthAppointmentService::class)->notifyUpcoming($this->hoje);

        Notification::assertSentTo($titular, HealthAppointmentUpcoming::class);
        Notification::assertSentTo($conjuge, HealthAppointmentUpcoming::class);
    }

    public function test_fora_da_janela_de_antecedencia_nao_notifica(): void
    {
        Notification::fake();
        [$perfil, $titular, $membroTitular] = $this->criarPerfil();

        HealthAppointment::withoutGlobalScopes()->create([
            'profile_id' => $perfil->id, 'member_id' => $membroTitular->id, 'kind' => 'exam',
            'title' => 'Daqui 5 dias', 'scheduled_at' => $this->hoje->addDays(5), 'created_by_member_id' => $membroTitular->id,
        ]);

        app(HealthAppointmentService::class)->notifyUpcoming($this->hoje);

        Notification::assertNothingSent();
    }

    public function test_reexecutar_no_mesmo_dia_nao_duplica_o_aviso(): void
    {
        // Sem Notification::fake(): a proteção contra duplicata consulta a
        // tabela `notifications` de verdade (ver HealthAppointmentService::alreadyNotifiedToday).
        [$perfil, $titular, $membroTitular] = $this->criarPerfil();

        HealthAppointment::withoutGlobalScopes()->create([
            'profile_id' => $perfil->id, 'member_id' => $membroTitular->id, 'kind' => 'consultation',
            'title' => 'Cardiologista', 'scheduled_at' => $this->hoje->addDay(), 'created_by_member_id' => $membroTitular->id,
        ]);

        app(HealthAppointmentService::class)->notifyUpcoming($this->hoje);
        app(HealthAppointmentService::class)->notifyUpcoming($this->hoje);

        self::assertSame(1, $titular->notifications()->where('type', HealthAppointmentUpcoming::class)->count());
    }

    /** @return array{0: FinancialProfile, 1: User, 2: ProfileMember} */
    private function criarPerfil(): array
    {
        $titular = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $titular->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $titular->id, 'role' => MemberRole::Primary]);

        return [$perfil, $titular, $membro];
    }

    /** @return array{0: FinancialProfile, 1: User, 2: ProfileMember, 3: User, 4: ProfileMember} */
    private function criarCasal(): array
    {
        [$perfil, $titular, $membroTitular] = $this->criarPerfil();

        $conjuge = User::factory()->create();
        $membroConjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $conjuge->id]);

        return [$perfil, $titular, $membroTitular, $conjuge, $membroConjuge];
    }
}
