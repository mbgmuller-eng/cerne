<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Livewire\Health\Gym\GymHome;
use App\Livewire\Health\Gym\GymSessionRun;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\GymPlanService;
use App\Services\GymProgressService;
use App\Services\GymSessionService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Evolução por exercício: melhor marca, platô, sugestão de subir a carga
 * (progressão dupla) e o gráfico que quebra na troca de equipamento.
 */
class GymProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;
    private FinancialProfile $perfil;
    private ProfileMember $membro;
    private GymWorkout $treino;
    private GymExercise $supino;
    private GymWorkoutExercise $item;
    private int $dia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $plano = app(GymPlanService::class);
        $this->treino = $plano->addWorkout($plano->createPlan('Fase 1'), 'Treino A', null);
        $this->supino = $plano->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps);
        $this->item = $plano->addExercise($this->treino, $this->supino, [
            'target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 12, 'rest_seconds' => 60,
        ]);
    }

    public function test_melhor_marca_e_a_maior_carga_e_depois_as_reps(): void
    {
        $this->treinar([['20', 10], ['20', 8]]);
        $this->treinar([['22', 8]]);
        $this->treinar([['22', 10], ['20', 12]]);

        $analise = app(GymProgressService::class)->analyze($this->supino);

        self::assertSame('kg', $analise['metric']);
        self::assertCount(3, $analise['sessions']);
        self::assertSame('22.00', $analise['best']['value']);
        self::assertSame(10, $analise['best']['tiebreak']);
    }

    public function test_treino_em_andamento_nao_entra_na_evolucao(): void
    {
        $this->treinar([['20', 10]]);
        $this->treinar([['30', 10]], finalizada: false);

        $analise = app(GymProgressService::class)->analyze($this->supino);

        self::assertCount(1, $analise['sessions']);
        self::assertSame('20.00', $analise['best']['value']);
    }

    public function test_sugere_subir_a_carga_quando_bate_o_teto_em_todas_as_series(): void
    {
        $this->treinar([['14', 12], ['14', 12], ['14', 12]], modo: GymLoadMode::PerDumbbell);

        $sugestao = app(GymProgressService::class)->analyze($this->supino, $this->item)['suggestion'];

        self::assertSame('raise', $sugestao['type']);
        self::assertSame('16', $sugestao['next_load']);
        self::assertSame('per_dumbbell', $sugestao['mode']);
        self::assertStringContainsString('14 kg', $sugestao['message']);
    }

    public function test_nao_sugere_quando_falta_serie_no_teto_ou_a_carga_variou(): void
    {
        $servico = app(GymProgressService::class);

        $this->treinar([['14', 12], ['14', 12], ['14', 11]], modo: GymLoadMode::PerDumbbell);
        self::assertNull($servico->analyze($this->supino, $this->item)['suggestion']);

        // Aquecimento leve + 2 séries de trabalho no teto: só 2 com a carga de topo.
        $this->treinar([['10', 12], ['14', 12], ['14', 12]], modo: GymLoadMode::PerDumbbell);
        self::assertNull($servico->analyze($this->supino, $this->item)['suggestion']);
    }

    public function test_sem_meta_no_plano_nao_ha_sugestao_de_carga(): void
    {
        $this->treinar([['14', 12], ['14', 12], ['14', 12]]);

        self::assertNull(app(GymProgressService::class)->analyze($this->supino)['suggestion']);
    }

    public function test_peso_do_corpo_puro_nao_sugere_carga(): void
    {
        $this->treinar([[null, 12], [null, 12], [null, 12]], modo: GymLoadMode::Bodyweight);

        $analise = app(GymProgressService::class)->analyze($this->supino, $this->item);

        self::assertSame('reps', $analise['metric']);
        self::assertNull($analise['suggestion']);
    }

    public function test_platô_depois_de_tres_treinos_sem_superar_o_melhor(): void
    {
        foreach ([['20', 10], ['22', 8], ['22', 8], ['20', 10], ['22', 8]] as $serie) {
            $this->treinar([$serie]);
        }

        $analise = app(GymProgressService::class)->analyze($this->supino);

        // Melhor foi o 2º treino (22 kg × 8); depois vieram 3 sem superar.
        self::assertSame(3, $analise['plateau']);
        self::assertSame('plateau', $analise['suggestion']['type']);
    }

    public function test_mais_repeticoes_na_mesma_carga_conta_como_evolucao(): void
    {
        foreach ([['22', 8], ['22', 8], ['22', 8], ['22', 9]] as $serie) {
            $this->treinar([$serie]);
        }

        self::assertNull(app(GymProgressService::class)->analyze($this->supino)['plateau']);
    }

    public function test_pouco_historico_nao_e_platô(): void
    {
        foreach ([['22', 8], ['20', 8], ['20', 8]] as $serie) {
            $this->treinar([$serie]);
        }

        self::assertNull(app(GymProgressService::class)->analyze($this->supino)['plateau']);
    }

    public function test_trocar_de_equipamento_reinicia_a_comparacao_e_quebra_o_grafico(): void
    {
        $plano = app(GymPlanService::class);
        $barra = $plano->findOrCreateEquipment('Barra');
        $smith = $plano->findOrCreateEquipment('Smith');

        foreach ([['30', 10], ['30', 10], ['30', 10]] as $serie) {
            $this->treinar([$serie], equipamento: $barra);
        }
        foreach ([['20', 10], ['20', 10], ['20', 10]] as $serie) {
            $this->treinar([$serie], equipamento: $smith);
        }

        $servico = app(GymProgressService::class);
        $analise = $servico->analyze($this->supino);

        // 6 treinos, mas só 3 no equipamento atual: ainda não dá pra falar em platô.
        self::assertNull($analise['plateau']);

        $grafico = $servico->chart($analise['sessions']);
        self::assertCount(2, $grafico['segments']);
        self::assertCount(1, $grafico['breaks']);
        self::assertCount(3, $grafico['segments'][0]);
    }

    public function test_equipamento_desconhecido_nao_quebra_a_linha(): void
    {
        $barra = app(GymPlanService::class)->findOrCreateEquipment('Barra');
        $this->treinar([['30', 10]], equipamento: $barra);
        $this->treinar([['32', 10]]); // sem equipamento registrado

        $servico = app(GymProgressService::class);

        self::assertCount(1, $servico->chart($servico->analyze($this->supino)['sessions'])['segments']);
    }

    public function test_exercicio_em_tempo_usa_a_duracao(): void
    {
        $plano = app(GymPlanService::class);
        $prancha = $plano->findOrCreateExercise('Prancha', GymMuscleGroup::Core, GymMeasureType::Duration);
        $this->treinarTempo($prancha, 40);
        $this->treinarTempo($prancha, 55);

        $analise = app(GymProgressService::class)->analyze($prancha);

        self::assertSame('duration', $analise['metric']);
        self::assertSame('55', $analise['best']['value']);
    }

    public function test_volume_soma_carga_vezes_repeticoes(): void
    {
        $this->treinar([['10', 10], ['20', 5]]);

        // 10×10 + 20×5 = 200
        self::assertSame('200.00', app(GymProgressService::class)->analyze($this->supino)['sessions'][0]['volume']);
    }

    public function test_paginas_abrem_com_grafico_e_sugestao(): void
    {
        $this->treinar([['14', 12], ['14', 12], ['14', 12]], modo: GymLoadMode::PerDumbbell);

        $this->get(route('health.gym.progress'))
            ->assertOk()
            ->assertSee('Supino')
            ->assertSee('Subir a carga');

        $this->get(route('health.gym.exercise', $this->supino->id))
            ->assertOk()
            ->assertSee('Hora de subir a carga')
            ->assertSee('Melhor marca')
            ->assertSee('<svg', false);
    }

    public function test_pagina_de_exercicio_sem_treino_finalizado_nao_quebra(): void
    {
        $this->get(route('health.gym.exercise', $this->supino->id))
            ->assertOk()
            ->assertSee('ainda não tem treinos finalizados');

        $this->get(route('health.gym.progress'))->assertOk()->assertSee('Ainda não há treinos finalizados');
    }

    public function test_sessao_mostra_a_sugestao_do_exercicio(): void
    {
        $this->treinar([['14', 12], ['14', 12], ['14', 12]], modo: GymLoadMode::PerDumbbell);
        $nova = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $nova->id])
            ->assertSee('Hora de subir a carga');
    }

    public function test_home_destaca_exercicios_para_olhar(): void
    {
        $this->treinar([['14', 12], ['14', 12], ['14', 12]], modo: GymLoadMode::PerDumbbell);

        Livewire::test(GymHome::class)
            ->assertSee('Para olhar')
            ->assertSee('Supino')
            ->assertSee('Subir a carga');
    }

    public function test_conjuge_nao_ve_a_evolucao_do_outro(): void
    {
        $this->treinar([['14', 12]]);

        $conjuge = ProfileMember::factory()->secondary()->create([
            'profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id,
        ]);
        app(ProfileContext::class)->set($this->perfil, $conjuge);

        self::assertTrue(app(GymProgressService::class)->overview()->isEmpty());

        $this->actingAs($conjuge->user)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.gym.exercise', $this->supino->id))
            ->assertNotFound();
    }

    public function test_consultor_leva_403_nas_telas_de_evolucao(): void
    {
        $this->treinar([['14', 12]]);

        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuario->id, 'status' => ConsultantClientStatus::Active,
        ]);

        foreach ([route('health.gym.progress'), route('health.gym.exercise', $this->supino->id)] as $url) {
            $this->actingAs($consultor)
                ->withSession(['cerne.active_profile_id' => $this->perfil->id])
                ->get($url)
                ->assertForbidden();
        }
    }

    /**
     * Uma sessão (finalizada por padrão) numa data nova, com as séries
     * [carga digitada, repetições].
     *
     * @param  list<array{0: ?string, 1: int}>  $series
     */
    private function treinar(array $series, ?GymLoadMode $modo = null, ?GymEquipment $equipamento = null, bool $finalizada = true): GymSession
    {
        $data = now()->subDays(100 - $this->dia++);

        $sessao = GymSession::create([
            'workout_id' => $this->treino->id,
            'performed_on' => $data->toDateString(),
            'started_at' => $data,
            'finished_at' => $finalizada ? $data->copy()->addHour() : null,
        ]);

        foreach ($series as $i => [$carga, $reps]) {
            GymSetLog::create([
                'session_id' => $sessao->id,
                'exercise_id' => $this->supino->id,
                'set_number' => $i + 1,
                'reps' => $reps,
                'load_value' => $carga,
                'load_mode' => $carga === null && $modo === null ? null : ($modo ?? GymLoadMode::Total),
                'equipment_id' => $equipamento?->id,
                'completed_at' => $data->copy()->addMinutes($i + 1),
            ]);
        }

        return $sessao;
    }

    private function treinarTempo(GymExercise $exercicio, int $segundos): void
    {
        $data = now()->subDays(100 - $this->dia++);
        $sessao = GymSession::create([
            'workout_id' => $this->treino->id, 'performed_on' => $data->toDateString(),
            'started_at' => $data, 'finished_at' => $data->copy()->addHour(),
        ]);
        GymSetLog::create([
            'session_id' => $sessao->id, 'exercise_id' => $exercicio->id, 'set_number' => 1,
            'duration_seconds' => $segundos, 'completed_at' => $data->copy()->addMinute(),
        ]);
    }
}
