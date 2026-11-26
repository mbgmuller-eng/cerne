<?php

namespace Tests\Feature;

use App\Enums\CaregiverAccess;
use App\Enums\CaregiverStatus;
use App\Enums\ConsultantClientStatus;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\HealthAppointmentKind;
use App\Enums\HealthAppointmentStatus;
use App\Enums\HealthFollowupStatus;
use App\Enums\HealthRoutineStatus;
use App\Enums\MemberRole;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymSessionRun;
use App\Livewire\Health\HealthAppointmentIndex;
use App\Livewire\Health\HealthFollowupIndex;
use App\Livewire\Health\HealthFollowupShow;
use App\Livewire\Health\HealthProgramPanel;
use App\Livewire\Health\HealthRoutineIndex;
use App\Models\ConsultantClient;
use App\Models\Document;
use App\Models\FinancialProfile;
use App\Models\HealthActivity;
use App\Models\HealthActivitySession;
use App\Models\HealthAppointment;
use App\Models\HealthExamRequest;
use App\Models\HealthFollowup;
use App\Models\HealthFollowupNote;
use App\Models\HealthRoutine;
use App\Models\ProfileCaregiver;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\HealthRoutineDue;
use App\Services\CareProfileService;
use App\Services\GymPlanService;
use App\Services\GymSessionService;
use App\Services\HealthActivityService;
use App\Services\HealthAppointmentService;
use App\Services\HealthFollowupService;
use App\Services\HealthRoutineService;
use App\Services\Privacy\AccountDeletionService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Cerne Saúde: check-ups e rotinas preventivas, acompanhamento de um problema (linha do tempo, exame pedido sem data, documentos, dor) e o
 * programa de atividades (exercícios à mão, presença, nota de dor, ligação opcional com a Academia). Visibilidade igual à da Agenda de Saúde.
 */
class HealthFollowupsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $joao;

    private FinancialProfile $perfil;

    private ProfileMember $membroAna;

    private ProfileMember $membroJoao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-26 09:00'));
        config(['cerne.care.enabled' => true]);

        $this->ana = User::factory()->create(['name' => 'Ana Teste']);
        $this->joao = User::factory()->create(['name' => 'João Teste']);
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->ana->id]);
        $this->membroAna = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->ana->id, 'name' => 'Ana Teste', 'role' => MemberRole::Primary]);
        $this->membroJoao = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->joao->id, 'name' => 'João Teste', 'role' => MemberRole::Secondary]);

        Subscription::create([
            'user_id' => $this->ana->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active,
            'current_period_ends_at' => now()->addMonth(), 'started_at' => now(),
        ]);

        $this->entrarComoAna();
    }

    // --- Check-ups e rotinas ------------------------------------------------------------------

    public function test_proxima_data_sai_da_ultima_vez_informada_ou_da_ultima_consulta_realizada(): void
    {
        $servico = app(HealthRoutineService::class);
        $rotina = $servico->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12, 'last_done_on' => '2025-03-10'], $this->membroAna);

        $this->assertSame('2026-03-10', $rotina->next_due_on->toDateString());
        $this->assertSame(HealthRoutineStatus::Late, $servico->status($rotina));

        // Só marcada não conta; realizada conta.
        $consulta = $this->consulta('Clínico', '2026-11-20 10:00');
        $servico->link($rotina, $consulta);
        $this->assertSame('2026-03-10', $rotina->fresh()->next_due_on->toDateString());

        app(HealthAppointmentService::class)->setStatus($consulta->fresh(), HealthAppointmentStatus::Done, $this->ana);
        $this->assertSame('2027-11-20', $rotina->fresh()->next_due_on->toDateString());
        $this->assertSame(HealthRoutineStatus::Ok, $servico->status($rotina->fresh()));

        // Tirar da rotina (ou apagar a consulta) volta para a última vez informada.
        $servico->unlink($consulta->fresh());
        $this->assertSame('2026-03-10', $rotina->fresh()->next_due_on->toDateString());

        $servico->link($rotina, $consulta->fresh());
        $consulta->fresh()->delete();
        $this->assertSame('2026-03-10', $rotina->fresh()->next_due_on->toDateString());
    }

    public function test_situacao_da_rotina_sem_registro_e_vencendo(): void
    {
        $servico = app(HealthRoutineService::class);

        $this->assertSame(HealthRoutineStatus::Never, $servico->status($servico->create($this->membroAna, ['name' => 'Dentista', 'interval_months' => 6], null)));
        // Vence em 30 dias: dentro da janela de 60.
        $vencendo = $servico->create($this->membroAna, ['name' => 'Oftalmo', 'interval_months' => 12, 'last_done_on' => '2025-12-26'], null);
        $this->assertSame(HealthRoutineStatus::Due, $servico->status($vencendo));

        $this->expectException(InvalidArgumentException::class);
        $servico->create($this->membroAna, ['name' => 'X', 'interval_months' => 7], null);
    }

    public function test_historico_mostra_o_ciclo_que_ficou_sem_registro_e_o_pedido_em_andamento(): void
    {
        $servico = app(HealthRoutineService::class);
        $rotina = $servico->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12, 'last_done_on' => '2024-05-02'], null);
        $consulta = $this->consulta('Clínico', '2026-11-20 10:00');
        $servico->link($rotina, $consulta);
        app(HealthAppointmentService::class)->setStatus($consulta->fresh(), HealthAppointmentStatus::Done, $this->ana);

        $ciclos = $servico->cycles($rotina->fresh(), $rotina->appointments()->get(), collect());
        $this->assertSame(['2026' => 'done', '2025' => 'missed', '2024' => 'done'], collect($ciclos)->pluck('state', 'label')->all());

        app(HealthFollowupService::class)->requestExam($this->membroAna, ['title' => 'Hemograma'], $rotina, null, null);
        $ciclos = $servico->cycles($rotina->fresh(), $rotina->appointments()->get(), $rotina->requests()->open()->get());
        $this->assertSame('progress', $ciclos[0]['state']);
        $this->assertStringContainsString('1 pedido sem data', $ciclos[0]['summary']);
    }

    public function test_aviso_sai_60_dias_antes_e_no_dia_uma_vez_como_o_lembrete_da_agenda(): void
    {
        $servico = app(HealthRoutineService::class);
        $servico->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12, 'last_done_on' => '2026-01-25'], null); // vence em 25/01/2027
        app(ProfileContext::class)->clear();

        // Em perfil comum, o mesmo público do lembrete de consulta: os dois do casal, cada um com o próprio consentimento.
        $this->assertSame(2, $servico->notifyDue());
        $this->assertSame(0, $servico->notifyDue(), 'rodar de novo no mesmo dia não repete');
        $this->assertSame(1, $this->ana->notifications()->where('type', HealthRoutineDue::class)->count());
        $this->assertSame(1, $this->joao->notifications()->where('type', HealthRoutineDue::class)->count());
        $this->assertStringNotContainsString('Check-up geral', (string) DB::table('notifications')->value('data'), 'o nome da rotina fica cifrado');

        $this->travelTo(CarbonImmutable::parse('2027-01-25 03:37'));
        $this->assertSame(2, $servico->notifyDue());
    }

    public function test_quem_ja_marcou_compromisso_da_rotina_nao_e_avisado(): void
    {
        Notification::fake();
        $servico = app(HealthRoutineService::class);
        $rotina = $servico->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12, 'last_done_on' => '2026-01-25'], null);
        $servico->link($rotina, $this->consulta('Clínico', '2027-01-10 10:00'));
        app(ProfileContext::class)->clear();

        $this->assertSame(0, $servico->notifyDue());
        Notification::assertNotSentTo($this->ana, HealthRoutineDue::class);
    }

    public function test_rotina_agendada_roda_de_ponta_a_ponta(): void
    {
        Notification::fake();
        app(HealthRoutineService::class)->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12, 'last_done_on' => '2025-11-26'], null);
        app(ProfileContext::class)->clear();

        $evento = collect(app(Schedule::class)->events())->firstWhere(fn ($e) => $e->description === 'rotinas-saude');
        $this->assertNotNull($evento);
        $evento->run($this->app);

        Notification::assertSentTo($this->ana, HealthRoutineDue::class);
    }

    // --- Acompanhamento ------------------------------------------------------------------------

    public function test_exame_pedido_fica_sem_data_ate_marcar_e_continua_ligado(): void
    {
        $servico = app(HealthFollowupService::class);
        $rotina = app(HealthRoutineService::class)->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12], null);
        $origem = $this->consulta('Clínico', '2026-11-20 10:00');
        $acompanhamento = $servico->create($this->membroAna, ['title' => 'Dor no ombro', 'status' => 'investigating', 'started_on' => '2026-11-20', 'appointment_id' => $origem->id], $this->membroAna);
        $pedido = $servico->requestExam($this->membroAna, ['title' => 'Ressonância do ombro', 'requested_by' => 'Dr. Paulo'], $rotina, $acompanhamento, null);
        $servico->addNote($acompanhamento, '2026-11-22', 'Doeu ao levantar o braço', 6, $this->membroAna);

        $linhas = $servico->timeline($acompanhamento);
        $this->assertSame(['appointment', 'note', 'request'], array_column($linhas, 'type'));
        $this->assertTrue($linhas[2]['pending']);
        $this->assertNull($linhas[2]['date']);
        $this->assertSame(1, HealthAppointment::query()->count(), 'o pedido ainda não está na Agenda');

        $marcado = $servico->scheduleRequest($pedido, '2026-12-04 08:30', $this->membroAna);

        $this->assertSame($rotina->id, $marcado->fresh()->routine_id);
        $this->assertSame(HealthAppointmentKind::Exam, $marcado->kind);
        $this->assertTrue($acompanhamento->appointments()->whereKey($marcado->id)->exists());
        $this->assertSame(0, $acompanhamento->requests()->open()->count());
        $this->assertSame(['appointment', 'note', 'appointment'], array_column($servico->timeline($acompanhamento), 'type'));

        $this->expectException(InvalidArgumentException::class);
        $servico->scheduleRequest($pedido->fresh(), '2026-12-05 08:30', null);
    }

    public function test_situacao_resolvido_guarda_a_data_e_reabrir_apaga(): void
    {
        $servico = app(HealthFollowupService::class);
        $acompanhamento = $this->acompanhamento();

        $servico->setStatus($acompanhamento, HealthFollowupStatus::Resolved);
        $this->assertSame('2026-11-26', $acompanhamento->fresh()->resolved_on->toDateString());

        $servico->setStatus($acompanhamento->fresh(), HealthFollowupStatus::Watching);
        $this->assertNull($acompanhamento->fresh()->resolved_on);
    }

    public function test_nada_de_uma_pessoa_se_liga_ao_acompanhamento_da_outra(): void
    {
        $servico = app(HealthFollowupService::class);
        $acompanhamento = $this->acompanhamento();
        $doJoao = app(HealthAppointmentService::class)->create($this->membroJoao, ['kind' => HealthAppointmentKind::Exam, 'title' => 'Raio X', 'scheduled_at' => '2026-12-01 10:00'], null);
        $rotinaDoJoao = app(HealthRoutineService::class)->create($this->membroJoao, ['name' => 'Check-up', 'interval_months' => 12], null);
        $documentoDoJoao = $this->documento($this->membroJoao);

        foreach ([
            fn () => $servico->attachAppointment($acompanhamento, $doJoao),
            fn () => $servico->linkDocument($acompanhamento, $documentoDoJoao),
            fn () => $servico->requestExam($this->membroAna, ['title' => 'X'], $rotinaDoJoao, null, null),
            fn () => $servico->requestExam($this->membroJoao, ['title' => 'X'], null, $acompanhamento, null),
            fn () => app(HealthRoutineService::class)->link($rotinaDoJoao, $this->consulta('Clínico', '2026-12-02 10:00')),
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('deveria recusar');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_ligar_documento_nao_muda_quem_pode_ve_lo(): void
    {
        $acompanhamento = $this->acompanhamento();
        $documento = $this->documento($this->membroAna);
        $antes = [$documento->category, $documento->visible_to_professional, $documento->shared_with_caregivers];

        app(HealthFollowupService::class)->linkDocument($acompanhamento, $documento);

        $depois = $documento->fresh();
        $this->assertSame($acompanhamento->id, $depois->health_followup_id);
        $this->assertEquals($antes, [$depois->category, $depois->visible_to_professional, $depois->shared_with_caregivers]);

        app(HealthFollowupService::class)->delete($acompanhamento);
        $this->assertNotNull($documento->fresh(), 'excluir o acompanhamento não apaga o documento');
        $this->assertNull($documento->fresh()->health_followup_id);
    }

    // --- Privacidade ---------------------------------------------------------------------------

    public function test_o_conjuge_ve_como_na_agenda_e_o_consultor_nao_ve_nada(): void
    {
        $this->tudoDaAna();

        $contexto = app(ProfileContext::class);
        $this->actingAs($this->joao);
        $contexto->set($this->perfil, $this->membroJoao);
        $this->assertSame([1, 1, 1, 1, 1, 1], $this->contagens());

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::query()->create(['consultant_id' => $consultor->id, 'client_id' => $this->ana->id, 'status' => ConsultantClientStatus::Active, 'invited_at' => now(), 'accepted_at' => now()]);
        $this->actingAs($consultor);
        $contexto->set($this->perfil, null, true);
        $this->assertSame([0, 0, 0, 0, 0, 0], $this->contagens());

        Livewire::test(HealthRoutineIndex::class)->assertForbidden();
        Livewire::test(HealthFollowupIndex::class)->assertForbidden();
    }

    public function test_quem_acompanha_so_a_ana_nao_ve_o_do_joao(): void
    {
        $this->tudoDaAna();
        app(HealthFollowupService::class)->create($this->membroJoao, ['title' => 'Joelho', 'status' => 'treating', 'started_on' => '2026-10-01'], null);

        $filha = User::factory()->create();
        $acesso = ProfileCaregiver::query()->create([
            'profile_id' => $this->perfil->id, 'member_id' => $this->membroAna->id, 'user_id' => $filha->id, 'invited_email' => $filha->email, 'invited_name' => $filha->name,
            'access_level' => CaregiverAccess::Read, 'status' => CaregiverStatus::Active, 'accepted_at' => now(), 'can_view_health' => true,
        ]);

        $this->actingAs($filha);
        app(ProfileContext::class)->set($this->perfil, null, false, $acesso);

        $this->assertSame(['Dor no ombro'], HealthFollowup::query()->get()->pluck('title')->all());
    }

    public function test_cuidador_que_so_le_nao_grava_e_o_que_edita_grava_com_o_nome_dele(): void
    {
        [$pessoa, $membro, $leitor, $editor] = $this->pessoaAcompanhada();
        $contexto = app(ProfileContext::class);
        $servico = app(HealthFollowupService::class);

        $this->actingAs($this->ana);
        $contexto->set($pessoa, null);
        $acompanhamento = $servico->create($membro, ['title' => 'Pressão', 'status' => 'watching', 'started_on' => '2026-10-01'], null);
        $consulta = app(HealthAppointmentService::class)->create($membro, ['kind' => HealthAppointmentKind::Consultation, 'title' => 'Cardio', 'scheduled_at' => '2026-12-01 10:00'], null);

        $this->actingAs($leitor);
        $contexto->set($pessoa, null, false, $this->acesso($pessoa, $leitor));
        $this->assertSame(1, HealthFollowup::query()->count());

        foreach ([
            fn () => $servico->addNote($acompanhamento, '2026-11-25', 'x', 3, null),
            fn () => $servico->setStatus($acompanhamento->fresh(), HealthFollowupStatus::Resolved),
            fn () => $servico->attachAppointment($acompanhamento, $consulta),
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('quem só lê não grava');
            } catch (HttpException|\Illuminate\Auth\Access\AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, DB::table('health_followup_appointment')->count());
        $this->assertSame(0, HealthFollowupNote::query()->count());

        Livewire::test(HealthFollowupShow::class, ['followup' => $acompanhamento->id])
            ->assertSee('data-situacao', false)
            ->assertDontSee('id="situacao"', false);

        $this->actingAs($editor);
        $contexto->set($pessoa, null, false, $this->acesso($pessoa, $editor));
        $nota = $servico->addNote($acompanhamento, '2026-11-25', 'Pressão alta', 2, null);
        $servico->attachAppointment($acompanhamento, $consulta);

        $this->assertSame($editor->id, $nota->created_by_user_id);
        $this->assertSame(1, DB::table('health_followup_appointment')->count());

        Livewire::test(HealthFollowupShow::class, ['followup' => $acompanhamento->id])->assertSee('id="situacao"', false);
    }

    public function test_telas_novas_abrem_para_o_cuidador_dentro_da_pessoa_acompanhada(): void
    {
        [$pessoa, $membro, $leitor] = $this->pessoaAcompanhada();
        $this->actingAs($this->ana);
        app(ProfileContext::class)->set($pessoa, null);
        $acompanhamento = app(HealthFollowupService::class)->create($membro, ['title' => 'Pressão', 'status' => 'watching', 'started_on' => '2026-10-01'], null);
        app(ProfileContext::class)->clear();

        $this->actingAs($leitor)->post(route('care.open', $pessoa->id))->assertRedirect();
        $this->get(route('health.routines.index'))->assertOk();
        $this->get(route('health.followups.index'))->assertOk()->assertSee('Pressão');
        $this->get(route('health.followups.show', $acompanhamento->id))->assertOk();
        $this->get(route('health.followups.pdf', $acompanhamento->id))->assertOk();
        $this->get(route('care.goto', ['profile' => $pessoa->id, 'destino' => 'rotinas']))->assertRedirect(route('health.routines.index'));
    }

    // --- Programa de atividades -------------------------------------------------------------------

    public function test_presenca_pede_dor_falta_nao_tem_nota_e_a_semana_soma_no_banco(): void
    {
        $servico = app(HealthActivityService::class);
        $fisio = $servico->create($this->acompanhamento(), ['kind' => 'physio', 'per_week' => 2, 'total_sessions' => 10], $this->membroAna);
        $pilates = $servico->create($fisio->followup, ['kind' => 'pilates', 'name' => 'Pilates solo', 'per_week' => 1], $this->membroAna);
        $this->assertSame('Fisioterapia', $fisio->name);

        $exercicio = $servico->addExercise($fisio, 'Rotação externa', '3', '12', 'elástico leve', null);
        $this->assertSame('3 × 12', $exercicio->dose);

        $servico->registerSession($fisio, '2026-11-16', true, 6, null, null);
        $servico->registerSession($pilates, '2026-11-18', true, 3, null, null);
        $servico->registerSession($fisio, '2026-11-19', false, 8, 'chuva', null);
        $servico->registerSession($fisio, '2026-11-24', true, 2, null, null);

        $this->assertNull(HealthActivitySession::query()->where('attended', false)->sole()->pain);

        $semanas = $servico->weekly([$fisio->id, $pilates->id]);
        $this->assertSame(['2026-11-16', '2026-11-23'], array_map(fn ($s) => $s['week']->toDateString(), $semanas));
        $this->assertSame([2, 1], array_column($semanas, 'sessions'));
        $this->assertSame([4.5, 2.0], array_column($semanas, 'pain'));

        $this->expectException(InvalidArgumentException::class);
        $servico->registerSession($fisio, '2026-11-25', true, null, null, null);
    }

    public function test_so_a_propria_fase_da_academia_pode_ser_ligada(): void
    {
        $fase = app(GymPlanService::class)->createPlan('Fortalecimento');
        $daAna = $this->acompanhamento();
        $servico = app(HealthActivityService::class);

        $this->assertSame([$fase->id], $servico->linkableGymPlans($daAna)->pluck('id')->all());
        $this->assertSame($fase->id, $servico->create($daAna, ['kind' => 'gym', 'per_week' => 3, 'gym_plan_id' => $fase->id], null)->gym_plan_id);
        // Fora da academia o vínculo é ignorado.
        $this->assertNull($servico->create($daAna, ['kind' => 'walking', 'per_week' => 3, 'gym_plan_id' => $fase->id], null)->gym_plan_id);

        // O cônjuge vê o programa, mas não a fase (cargas e séries são só da dona) e não liga a dela.
        $this->actingAs($this->joao);
        app(ProfileContext::class)->set($this->perfil, $this->membroJoao);
        $this->assertSame(2, HealthActivity::query()->count());
        $this->assertNull(HealthActivity::query()->whereNotNull('gym_plan_id')->sole()->gymPlan);
        $this->assertCount(0, $servico->linkableGymPlans($daAna->fresh()));

        $this->expectException(InvalidArgumentException::class);
        $servico->create($daAna->fresh(), ['kind' => 'gym', 'per_week' => 3, 'gym_plan_id' => $fase->id], null);
    }

    public function test_terminar_o_treino_da_fase_ligada_registra_a_presenca_uma_vez_e_pede_a_dor(): void
    {
        $planos = app(GymPlanService::class);
        $fase = $planos->createPlan('Fortalecimento');
        $treino = $planos->addWorkout($fase, 'Treino A', null);
        $item = $planos->addExercise($treino, $planos->findOrCreateExercise('Remada', GymMuscleGroup::Chest, GymMeasureType::LoadReps), ['target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 12]);

        $acompanhamento = $this->acompanhamento();
        $atividade = app(HealthActivityService::class)->create($acompanhamento, ['kind' => 'gym', 'per_week' => 3, 'gym_plan_id' => $fase->id], null);

        $sessao = app(GymSessionService::class)->start($treino);
        app(GymSessionService::class)->recordSet($sessao, $item, 1, ['reps' => 10]);

        $tela = Livewire::test(GymSessionRun::class, ['session' => $sessao->id])->call('finish');
        $presenca = HealthActivitySession::query()->sole();
        $tela->assertRedirect(route('health.followups.show', ['followup' => $acompanhamento->id, 'presenca' => $presenca->id]));

        $this->assertSame($atividade->id, $presenca->activity_id);
        $this->assertTrue($presenca->attended);
        $this->assertNull($presenca->pain);

        app(HealthActivityService::class)->recordGymSession($sessao->fresh());
        $this->assertSame(1, HealthActivitySession::query()->count());

        Livewire::withQueryParams(['presenca' => $presenca->id])
            ->test(HealthProgramPanel::class, ['followupId' => $acompanhamento->id])
            ->assertSet('painFor', $presenca->id)
            ->assertSee('Como ficou a dor neste treino?')
            ->call('savePain')->assertHasErrors('painValue')
            ->set('painValue', 4)->call('savePain')->assertHasNoErrors()->assertSet('painFor', null);

        $this->assertSame(4, $presenca->fresh()->pain);
    }

    // --- Telas e PDF -----------------------------------------------------------------------------

    public function test_cadastro_de_rotina_pela_tela_com_o_ao_salvar(): void
    {
        Livewire::test(HealthRoutineIndex::class)
            ->call('newRoutine')
            ->set('name', 'Check-up geral')->set('interval', '12')->set('lastDoneOn', '2026-03-05')
            ->assertSee('A próxima fica para 05/03/2027')
            ->call('save')->assertHasNoErrors()
            ->assertSee('Check-up geral')
            ->assertSee('+ Pedido de exames');

        $rotina = HealthRoutine::query()->sole();
        $this->assertSame('2027-03-05', $rotina->next_due_on->toDateString());

        app(HealthFollowupService::class)->requestExam($this->membroAna, ['title' => 'Hemograma'], $rotina, null, null);
        $this->assertSame($rotina->id, HealthExamRequest::query()->sole()->routine_id);
        Livewire::test(HealthRoutineIndex::class)->assertSee('Falta agendar: Hemograma')->assertSee('Abrir pedido');
    }

    public function test_acompanhamento_pela_tela_com_anotacao_envio_de_documento_e_programa(): void
    {
        Storage::fake(config('cerne.document_vault.disk'));
        $origem = $this->consulta('Ortopedista', '2026-11-20 10:00');

        Livewire::withQueryParams(['consulta' => $origem->id])->test(HealthFollowupIndex::class)
            ->assertSet('appointmentId', $origem->id)
            ->set('title', 'Dor no ombro')->call('save')->assertHasNoErrors();

        $acompanhamento = HealthFollowup::query()->sole();
        $this->assertTrue($acompanhamento->appointments()->whereKey($origem->id)->exists());

        Livewire::test(HealthFollowupShow::class, ['followup' => $acompanhamento->id])
            ->call('open', 'note')->call('saveNote')->assertHasErrors('noteText')
            ->set('notePain', 5)->call('saveNote')->assertHasNoErrors()
            ->assertSee('Dor 5 de 10')
            ->call('open', 'document')
            ->set('documentTitle', 'Laudo do ombro')->set('arquivo', UploadedFile::fake()->create('laudo.pdf', 20, 'application/pdf'))
            ->call('uploadDocument')->assertHasNoErrors()
            ->assertSee('Laudo do ombro')
            ->call('changeStatus', 'treating');

        $this->assertSame(HealthFollowupStatus::Treating, $acompanhamento->fresh()->status);
        $this->assertSame($acompanhamento->id, Document::query()->sole()->health_followup_id);

        Livewire::test(HealthProgramPanel::class, ['followupId' => $acompanhamento->id])
            ->call('open', 'program')->set('guidedBy', 'Fisio Marina')->set('avoid', 'Peso acima da cabeça')->call('saveProgram')->assertHasNoErrors()
            ->assertSee('Peso acima da cabeça')
            ->call('open', 'activity')->set('perWeek', '2')->call('saveActivity')->assertHasNoErrors()
            ->call('open', 'exercise')->set('exerciseName', 'Pêndulo')->set('exerciseSets', '2')->set('exerciseReps', '30 s')->call('saveExercise')->assertHasNoErrors()
            ->assertSee('2 × 30 s')
            ->call('open', 'session')->call('saveSession')->assertHasErrors('sessionPain')
            ->set('sessionPain', 3)->call('saveSession')->assertHasNoErrors()
            ->assertSee('Dor média da semana');

        $this->assertSame(1, HealthActivitySession::query()->count());
    }

    public function test_id_de_outro_acompanhamento_vindo_do_navegador_e_recusado(): void
    {
        $um = $this->acompanhamento();
        $outro = app(HealthFollowupService::class)->create($this->membroAna, ['title' => 'Joelho', 'status' => 'treating', 'started_on' => '2026-10-01'], null);
        $alheia = app(HealthActivityService::class)->create($outro, ['kind' => 'physio', 'per_week' => 2], null);
        $nota = app(HealthFollowupService::class)->addNote($outro, '2026-11-01', 'x', null, null);

        Livewire::test(HealthProgramPanel::class, ['followupId' => $um->id])->call('selectActivity', $alheia->id)->assertNotFound();
        Livewire::test(HealthFollowupShow::class, ['followup' => $um->id])->call('deleteNote', $nota->id)->assertNotFound();
        $this->assertNotNull($nota->fresh());
    }

    public function test_a_agenda_liga_a_consulta_ao_check_up_e_ao_acompanhamento_ao_mesmo_tempo(): void
    {
        $rotina = app(HealthRoutineService::class)->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12], null);
        $acompanhamento = $this->acompanhamento();

        Livewire::test(HealthAppointmentIndex::class)
            ->call('newAppointment')
            ->set('memberId', $this->membroAna->id)
            ->set('title', 'Clínico geral')->set('scheduledDate', '2026-12-10')->set('scheduledTime', '09:00')
            ->set('routineId', $rotina->id)->set('followupId', $acompanhamento->id)
            ->call('save')->assertHasNoErrors();

        $consulta = HealthAppointment::query()->sole();
        $this->assertSame($rotina->id, $consulta->routine_id);
        $this->assertTrue($acompanhamento->appointments()->whereKey($consulta->id)->exists());

        // Editar e tirar o acompanhamento solta só a ligação.
        Livewire::test(HealthAppointmentIndex::class)
            ->call('editAppointment', $consulta->id)
            ->assertSet('followupId', $acompanhamento->id)
            ->set('followupId', '')->call('save')->assertHasNoErrors();

        $this->assertSame(0, DB::table('health_followup_appointment')->count());
        $this->assertSame($rotina->id, $consulta->fresh()->routine_id);

        // Rotina de outra pessoa não entra, e nada é gravado.
        $doJoao = app(HealthRoutineService::class)->create($this->membroJoao, ['name' => 'Dele', 'interval_months' => 12], null);
        Livewire::test(HealthAppointmentIndex::class)
            ->call('editAppointment', $consulta->id)
            ->set('title', 'Não pode gravar')->set('routineId', $doJoao->id)->call('save')->assertNotFound();
        $this->assertSame('Clínico geral', $consulta->fresh()->title);
    }

    public function test_pdf_para_o_medico_nao_leva_o_problema_no_nome_do_arquivo(): void
    {
        $acompanhamento = $this->tudoDaAna();
        app(ProfileContext::class)->clear();

        $resposta = $this->actingAs($this->ana)->get(route('health.followups.pdf', $acompanhamento->id));

        $resposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('resumo-de-saude-ana-teste-2026-11-26.pdf', (string) $resposta->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('ombro', (string) $resposta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_sem_consentimento_de_saude_as_telas_novas_ficam_fechadas(): void
    {
        $semConsentimento = User::factory()->create(['health_consent_at' => null, 'health_consent_version' => null]);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $semConsentimento->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $semConsentimento->id]);
        app(ProfileContext::class)->clear();

        foreach (['health.routines.index', 'health.followups.index'] as $rota) {
            $this->actingAs($semConsentimento)->get(route($rota))->assertRedirect();
        }
    }

    public function test_excluir_a_conta_leva_junto_os_registros_novos(): void
    {
        $this->tudoDaAna();
        app(ProfileContext::class)->clear();

        app(AccountDeletionService::class)->delete($this->ana);

        foreach (['health_routines', 'health_followups', 'health_followup_notes', 'health_followup_appointment', 'health_exam_requests', 'health_activities', 'health_activity_exercises', 'health_activity_sessions'] as $tabela) {
            $this->assertSame(0, DB::table($tabela)->count(), $tabela);
        }
    }

    // ---------------------------------------------------------------------------------------------

    private function entrarComoAna(): void
    {
        $this->actingAs($this->ana);
        app(ProfileContext::class)->set($this->perfil, $this->membroAna);
    }

    private function consulta(string $titulo, string $quando): HealthAppointment
    {
        return app(HealthAppointmentService::class)->create($this->membroAna, ['kind' => HealthAppointmentKind::Consultation, 'title' => $titulo, 'scheduled_at' => $quando], $this->membroAna);
    }

    private function acompanhamento(): HealthFollowup
    {
        return app(HealthFollowupService::class)->create($this->membroAna, ['title' => 'Dor no ombro', 'status' => 'investigating', 'started_on' => '2026-11-20'], $this->membroAna);
    }

    private function documento(ProfileMember $membro): Document
    {
        return Document::create([
            'member_id' => $membro->id, 'category' => 'health_exam', 'title' => 'Laudo', 'original_filename' => 'a.pdf',
            'storage_path' => 'documentos-pessoais/a-'.$membro->id.'.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        ]);
    }

    /** Uma de cada coisa nova, da Ana. */
    private function tudoDaAna(): HealthFollowup
    {
        $rotina = app(HealthRoutineService::class)->create($this->membroAna, ['name' => 'Check-up geral', 'interval_months' => 12], $this->membroAna);
        $acompanhamento = $this->acompanhamento();
        $servico = app(HealthFollowupService::class);
        $servico->attachAppointment($acompanhamento, $this->consulta('Ortopedista', '2026-11-20 10:00'));
        $servico->addNote($acompanhamento, '2026-11-21', 'Doeu', 5, $this->membroAna);
        $servico->requestExam($this->membroAna, ['title' => 'Ressonância do ombro'], $rotina, $acompanhamento, $this->membroAna);

        $programa = app(HealthActivityService::class);
        $atividade = $programa->create($acompanhamento, ['kind' => 'physio', 'per_week' => 2], $this->membroAna);
        $programa->addExercise($atividade, 'Pêndulo', '2', '30 s', null, $this->membroAna);
        $programa->registerSession($atividade, '2026-11-24', true, 4, null, $this->membroAna);

        return $acompanhamento;
    }

    /** @return list<int> rotinas, acompanhamentos, anotações, pedidos, atividades, presenças que o contexto atual enxerga */
    private function contagens(): array
    {
        return [
            HealthRoutine::query()->count(), HealthFollowup::query()->count(), HealthFollowupNote::query()->count(),
            HealthExamRequest::query()->count(), HealthActivity::query()->count(), HealthActivitySession::query()->count(),
        ];
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember, 2: User, 3: User} pessoa, membro dela, quem só lê e quem edita */
    private function pessoaAcompanhada(): array
    {
        app(ProfileContext::class)->clear();
        $pessoa = app(CareProfileService::class)->create($this->ana, 'Maria Souza', null, CareProfileService::BASIS_AUTHORIZED);
        $membro = ProfileMember::query()->where('profile_id', $pessoa->id)->sole();
        $pessoas = [];

        foreach ([CaregiverAccess::Read, CaregiverAccess::Edit] as $nivel) {
            $usuario = User::factory()->create();
            ProfileCaregiver::query()->create([
                'profile_id' => $pessoa->id, 'user_id' => $usuario->id, 'invited_email' => $usuario->email, 'invited_name' => $usuario->name,
                'access_level' => $nivel, 'status' => CaregiverStatus::Active, 'accepted_at' => now(),
            ]);
            $pessoas[] = $usuario;
        }

        return [$pessoa, $membro, ...$pessoas];
    }

    private function acesso(FinancialProfile $pessoa, User $usuario): ProfileCaregiver
    {
        return ProfileCaregiver::query()->active()->where('profile_id', $pessoa->id)->where('user_id', $usuario->id)->sole();
    }
}
