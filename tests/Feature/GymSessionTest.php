<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymHome;
use App\Livewire\Health\Gym\GymSessionRun;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymPlanService;
use App\Services\GymSessionService;
use App\Support\ProfileContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cerne Saúde › Academia — registro do treino: abrir/retomar a sessão,
 * uma linha por série, pausa, cronômetro e os interruptores globais.
 */
class GymSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;
    private FinancialProfile $perfil;
    private ProfileMember $membro;
    private GymWorkout $treino;
    private GymWorkoutExercise $supino;
    private GymWorkoutExercise $prancha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
        // Academia é gateada por EntitlementService (módulo Saúde) — sem
        // isso, GymHome dá 402.
        Subscription::create([
            'user_id' => $this->usuario->id, 'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);

        $plano = app(GymPlanService::class);
        $this->treino = $plano->addWorkout($plano->createPlan('Fase 1'), 'Treino A', null);
        $this->supino = $plano->addExercise(
            $this->treino,
            $plano->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps),
            ['target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 12, 'rest_seconds' => 90],
        );
        $this->prancha = $plano->addExercise(
            $this->treino,
            $plano->findOrCreateExercise('Prancha', GymMuscleGroup::Core, GymMeasureType::Duration),
            ['target_sets' => 2, 'target_duration_seconds' => 45],
        );
    }

    public function test_iniciar_cria_a_sessao_e_iniciar_de_novo_retoma_a_mesma(): void
    {
        $servico = app(GymSessionService::class);

        $um = $servico->start($this->treino);
        $dois = $servico->start($this->treino);

        self::assertSame($um->id, $dois->id);
        self::assertTrue($um->isInProgress());
        self::assertSame(1, GymSession::query()->count());
    }

    public function test_so_uma_sessao_aberta_mesmo_iniciando_outro_treino(): void
    {
        $plano = app(GymPlanService::class);
        $outro = $plano->addWorkout($plano->activePlan(), 'Treino B', null);
        $servico = app(GymSessionService::class);

        $a = $servico->start($this->treino);

        self::assertSame($a->id, $servico->start($outro)->id);
    }

    public function test_concluir_serie_grava_carga_derivada_e_dispara_a_pausa(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->set("draft.{$this->supino->id}.load", '14')
            ->set("draft.{$this->supino->id}.mode", GymLoadMode::PerDumbbell->value)
            ->set("draft.{$this->supino->id}.reps", '12')
            ->call('completeSet', $this->supino->id, 1)
            ->assertHasNoErrors()
            ->assertDispatched('gym-rest-start', seconds: 90);

        $serie = GymSetLog::query()->firstOrFail();
        self::assertSame(1, $serie->set_number);
        self::assertSame(12, $serie->reps);
        self::assertSame('14.00', $serie->load_value);
        self::assertSame('28.00', $serie->load_total_kg);
        self::assertNotNull($serie->completed_at);
    }

    public function test_aceita_virgula_como_separador_decimal(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->set("draft.{$this->supino->id}.load", '12,5')
            ->set("draft.{$this->supino->id}.reps", '10')
            ->call('completeSet', $this->supino->id, 1)
            ->assertHasNoErrors();

        self::assertSame('12.50', GymSetLog::query()->firstOrFail()->load_value);
    }

    public function test_toque_duplo_nao_cria_serie_repetida_nem_reinicia_a_pausa(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        $tela = Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->set("draft.{$this->supino->id}.reps", '10')
            ->call('completeSet', $this->supino->id, 1);

        // Segundo toque, com a tela ainda mostrando "série 1".
        $tela->call('completeSet', $this->supino->id, 1);

        self::assertSame(1, GymSetLog::query()->count());
    }

    public function test_repeticoes_sao_obrigatorias(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->set("draft.{$this->supino->id}.reps", '')
            ->call('completeSet', $this->supino->id, 1)
            ->assertHasErrors(["draft.{$this->supino->id}.reps"]);

        self::assertSame(0, GymSetLog::query()->count());
    }

    public function test_carga_invalida_e_recusada(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->set("draft.{$this->supino->id}.load", 'abc')
            ->set("draft.{$this->supino->id}.reps", '10')
            ->call('completeSet', $this->supino->id, 1)
            ->assertHasErrors(["draft.{$this->supino->id}.load"]);
    }

    public function test_exercicio_em_tempo_grava_a_duracao_e_nao_dispara_pausa(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->call('completeTimedSet', $this->prancha->id, 1, 47)
            ->assertNotDispatched('gym-rest-start');

        $serie = GymSetLog::query()->firstOrFail();
        self::assertSame(47, $serie->duration_seconds);
        self::assertNull($serie->reps);
        self::assertNull($serie->load_value);
    }

    public function test_exercicio_em_tempo_nao_aceita_serie_por_repeticoes_e_vice_versa(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->call('completeSet', $this->prancha->id, 1)
            ->assertStatus(422);

        self::assertSame(0, GymSetLog::query()->count());
    }

    public function test_desfazer_remove_a_ultima_serie(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);
        $servico = app(GymSessionService::class);
        $servico->recordSet($sessao, $this->supino, 1, ['reps' => 10]);
        $servico->recordSet($sessao, $this->supino, 2, ['reps' => 9]);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->call('undoSet', $this->supino->id)
            ->assertDispatched('gym-rest-cancel');

        self::assertSame([1], GymSetLog::query()->pluck('set_number')->all());

        // E a série 2 pode ser refeita.
        self::assertNotNull($servico->recordSet($sessao, $this->supino, 2, ['reps' => 8]));
    }

    public function test_serie_fora_de_ordem_e_ignorada(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        self::assertNull(app(GymSessionService::class)->recordSet($sessao, $this->supino, 3, ['reps' => 10]));
        self::assertSame(0, GymSetLog::query()->count());
    }

    public function test_finalizar_exige_serie_e_avanca_a_rotacao(): void
    {
        $plano = app(GymPlanService::class);
        $treinoB = $plano->addWorkout($plano->activePlan(), 'Treino B', null);
        $servico = app(GymSessionService::class);
        $sessao = $servico->start($this->treino);

        $tela = Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->call('finish')
            ->assertHasErrors('session')
            ->assertNoRedirect();

        self::assertTrue($sessao->fresh()->isInProgress());

        $servico->recordSet($sessao, $this->supino, 1, ['reps' => 10]);
        $tela->call('finish')->assertRedirect(route('health.gym.index'));

        self::assertFalse($sessao->fresh()->isInProgress());
        self::assertSame($treinoB->id, $plano->nextWorkout($plano->activePlan())->id);
    }

    public function test_sessao_finalizada_nao_aceita_mais_series(): void
    {
        $servico = app(GymSessionService::class);
        $sessao = $servico->start($this->treino);
        $servico->recordSet($sessao, $this->supino, 1, ['reps' => 10]);
        $servico->finish($sessao);

        $this->expectException(DomainException::class);
        $servico->recordSet($sessao->fresh(), $this->supino, 2, ['reps' => 10]);
    }

    public function test_exercicio_de_outro_treino_e_recusado(): void
    {
        $plano = app(GymPlanService::class);
        $treinoB = $plano->addWorkout($plano->activePlan(), 'Treino B', null);
        $doB = $plano->addExercise($treinoB, $this->supino->exercise, ['target_sets' => 3]);
        $sessao = app(GymSessionService::class)->start($this->treino);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->set("draft.{$doB->id}.reps", '10') // chave inexistente: não deve nem chegar a gravar
            ->call('completeSet', $doB->id, 1);
    }

    public function test_descartar_apaga_a_sessao_e_as_series(): void
    {
        $servico = app(GymSessionService::class);
        $sessao = $servico->start($this->treino);
        $servico->recordSet($sessao, $this->supino, 1, ['reps' => 10]);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->call('discard')
            ->assertRedirect(route('health.gym.index'));

        self::assertSame(0, GymSession::query()->count());
        self::assertSame(0, GymSetLog::query()->count());
    }

    public function test_proxima_sessao_vem_preenchida_com_a_ultima_vez(): void
    {
        $servico = app(GymSessionService::class);
        $primeira = $servico->start($this->treino);
        $servico->recordSet($primeira, $this->supino, 1, ['load' => '14', 'mode' => 'per_dumbbell', 'reps' => 12]);
        $servico->recordSet($primeira, $this->supino, 2, ['load' => '16', 'mode' => 'per_dumbbell', 'reps' => 10]);
        $servico->finish($primeira);

        $segunda = $servico->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $segunda->id])
            ->assertSet("draft.{$this->supino->id}.load", '16')
            ->assertSet("draft.{$this->supino->id}.mode", 'per_dumbbell')
            ->assertSee('Última vez')
            ->assertSee('12, 10 reps');
    }

    public function test_recarregar_o_treino_no_meio_mantem_a_carga_de_hoje(): void
    {
        $servico = app(GymSessionService::class);
        $ontem = $servico->start($this->treino);
        $servico->recordSet($ontem, $this->supino, 1, ['load' => '10', 'mode' => 'total', 'reps' => 12]);
        $servico->finish($ontem);

        $hoje = $servico->start($this->treino);
        $servico->recordSet($hoje, $this->supino, 1, ['load' => '14', 'mode' => 'per_dumbbell', 'reps' => 10]);

        // Nova montagem da tela (recarregou a página): a carga de hoje, não a de ontem.
        Livewire::test(GymSessionRun::class, ['session' => $hoje->id])
            ->assertSet("draft.{$this->supino->id}.load", '14')
            ->assertSet("draft.{$this->supino->id}.mode", 'per_dumbbell')
            ->assertSee('10 kg');
    }

    public function test_equipamento_de_outra_pessoa_e_ignorado(): void
    {
        $outro = ProfileMember::factory()->secondary()->create([
            'profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id,
        ]);
        app(ProfileContext::class)->set($this->perfil, $outro);
        $alheio = app(GymPlanService::class)->findOrCreateEquipment('Halteres do outro');
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $sessao = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->set("draft.{$this->supino->id}.reps", '10')
            ->set("draft.{$this->supino->id}.equipment", $alheio->id)
            ->call('completeSet', $this->supino->id, 1);

        self::assertNull(GymSetLog::query()->firstOrFail()->equipment_id);
    }

    public function test_interruptores_globais_salvam_na_conta(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        // Padrões: tela ligada, sem vibração, sem som.
        self::assertTrue($this->usuario->fresh()->gym_keep_awake);
        self::assertFalse($this->usuario->fresh()->gym_vibrate);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->assertSet('keepAwake', true)
            ->set('keepAwake', false)
            ->set('vibrate', true)
            ->set('sound', true);

        $usuario = $this->usuario->fresh();
        self::assertFalse($usuario->gym_keep_awake);
        self::assertTrue($usuario->gym_vibrate);
        self::assertTrue($usuario->gym_sound);

        // E valem para o próximo treino, em outra tela.
        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->assertSet('keepAwake', false)
            ->assertSet('vibrate', true);
    }

    public function test_home_inicia_o_treino_e_mostra_o_banner_de_retomada(): void
    {
        Livewire::test(GymHome::class)
            ->assertSee('Iniciar treino')
            ->call('startSession', $this->treino->id)
            ->assertRedirect(route('health.gym.session', GymSession::query()->firstOrFail()->id));

        Livewire::test(GymHome::class)
            ->assertSee('Treino em andamento')
            ->assertSee('Retomar treino')
            ->assertDontSee('Iniciar treino');
    }

    public function test_conjuge_nao_abre_a_sessao_do_outro(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        $conjuge = ProfileMember::factory()->secondary()->create([
            'profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id,
        ]);
        $this->actingAs($conjuge->user)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.gym.session', $sessao->id))
            ->assertNotFound();
    }

    public function test_consultor_com_cliente_aberto_leva_403_na_sessao(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuario->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.gym.session', $sessao->id))
            ->assertForbidden();
    }

    public function test_tela_abre_e_mostra_os_exercicios(): void
    {
        $sessao = app(GymSessionService::class)->start($this->treino);

        $this->get(route('health.gym.session', $sessao->id))
            ->assertOk()
            ->assertSee('Supino')
            ->assertSee('Prancha')
            ->assertSee('Iniciar cronômetro')
            ->assertSee('Finalizar treino');
    }
}
