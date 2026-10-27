<?php

namespace Tests\Feature;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymPlanEditor;
use App\Livewire\Health\Gym\GymSessionRun;
use App\Models\FinancialProfile;
use App\Models\GymExerciseCatalog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymPlanService;
use App\Services\GymSessionService;
use App\Services\GymWorkoutGenerator;
use App\Support\GymGeneratorInput;
use App\Support\ProfileContext;
use Database\Seeders\GymExerciseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Condicionamento intervalado: cada série é um esforço no cronômetro e a pausa leve entre elas conta em regressiva, como a prancha tem
 * várias séries. O total de séries é definido de antemão (8 esforços de 30 s com 60 s leve), não um cronômetro único e longo.
 */
class GymIntervalConditioningTest extends TestCase
{
    use RefreshDatabase;

    private GymWorkout $treino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GymExerciseCatalogSeeder::class);

        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo,
            'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);

        $plano = app(GymPlanService::class);
        $this->treino = $plano->addWorkout($plano->createPlan('Fase 1'), 'Treino C', null);
    }

    private function intervalado(array $meta): GymWorkoutExercise
    {
        $plano = app(GymPlanService::class);

        return $plano->addExercise($this->treino, $plano->findOrCreateExercise('Condicionamento em Intervalos', GymMuscleGroup::Cardio, GymMeasureType::Duration), $meta);
    }

    public function test_o_gerador_monta_o_hiit_em_series_de_esforco_com_pausa_leve(): void
    {
        $hiit = GymExerciseCatalog::query()->where('name', 'HIIT em Intervalos (esteira/bike)')->firstOrFail();

        $linha = app(GymWorkoutGenerator::class)->rowFor($hiit->id, new GymGeneratorInput(GymGoal::WeightLoss, GymLevel::Intermediate, 3, 60, GymEquipmentAccess::FullGym));

        // 60 min: bloco de 20 min (1200 s) cabe em 13 rodadas de 90 s, limitado a 12.
        self::assertSame([12, 30, 60], [$linha['sets'], $linha['duration'], $linha['rest']]);
        self::assertStringContainsString('12 esforços de 30s', $linha['notes']);

        $curto = app(GymWorkoutGenerator::class)->rowFor($hiit->id, new GymGeneratorInput(GymGoal::WeightLoss, GymLevel::Beginner, 3, 30, GymEquipmentAccess::FullGym));
        self::assertSame([7, 20, 60], [$curto['sets'], $curto['duration'], $curto['rest']], '10 min de bloco, esforço mais curto para iniciante');
    }

    public function test_cardio_continuo_segue_com_uma_serie_e_um_cronometro(): void
    {
        $esteira = GymExerciseCatalog::query()->where('name', 'Esteira (caminhada/corrida)')->firstOrFail();

        $linha = app(GymWorkoutGenerator::class)->rowFor($esteira->id, new GymGeneratorInput(GymGoal::WeightLoss, GymLevel::Intermediate, 3, 45, GymEquipmentAccess::FullGym));

        self::assertSame([1, 900, null], [$linha['sets'], $linha['duration'], $linha['rest']]);
    }

    public function test_exercicio_em_tempo_guarda_a_pausa_entre_series(): void
    {
        $item = $this->intervalado(['target_sets' => 8, 'target_duration_seconds' => 30, 'rest_seconds' => 60]);

        $item = $item->fresh();
        self::assertSame([8, 30, 60], [$item->target_sets, $item->target_duration_seconds, $item->rest_seconds]);
        self::assertNull($item->target_reps_min);

        // Prancha, sem pausa, continua sem.
        $plano = app(GymPlanService::class);
        $prancha = $plano->addExercise($this->treino, $plano->findOrCreateExercise('Prancha', GymMuscleGroup::Core, GymMeasureType::Duration), ['target_sets' => 3, 'target_duration_seconds' => 40]);
        self::assertNull($prancha->fresh()->rest_seconds);
    }

    public function test_editor_salva_as_series_o_tempo_e_a_pausa_do_intervalado(): void
    {
        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $this->treino->id)
            ->set('exName', 'Intervalado na esteira')
            ->set('exGroup', 'cardio')
            ->set('exType', 'duration')
            ->set('exSets', '8')
            ->set('exDuration', '30')
            ->set('exRest', '60')
            ->call('saveExercise')
            ->assertHasNoErrors();

        $item = GymWorkoutExercise::query()->firstOrFail();
        self::assertSame([8, 30, 60], [$item->target_sets, $item->target_duration_seconds, $item->rest_seconds]);
    }

    public function test_na_sessao_a_pausa_conta_depois_de_cada_esforco_menos_do_ultimo(): void
    {
        $item = $this->intervalado(['target_sets' => 3, 'target_duration_seconds' => 30, 'rest_seconds' => 60]);
        $sessao = app(GymSessionService::class)->start($this->treino);

        $tela = Livewire::test(GymSessionRun::class, ['session' => $sessao->id]);

        $tela->call('completeTimedSet', $item->id, 1, 31)->assertDispatched('gym-rest-start', seconds: 60);
        $tela->call('completeTimedSet', $item->id, 2, 30)->assertDispatched('gym-rest-start', seconds: 60);
        // O último esforço fecha o exercício: sem pausa depois dele.
        $tela->call('completeTimedSet', $item->id, 3, 30)->assertNotDispatched('gym-rest-start');
    }

    public function test_prancha_sem_pausa_continua_sem_contagem_regressiva(): void
    {
        $plano = app(GymPlanService::class);
        $prancha = $plano->addExercise($this->treino, $plano->findOrCreateExercise('Prancha', GymMuscleGroup::Core, GymMeasureType::Duration), ['target_sets' => 2, 'target_duration_seconds' => 45]);
        $sessao = app(GymSessionService::class)->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->call('completeTimedSet', $prancha->id, 1, 47)
            ->assertNotDispatched('gym-rest-start');
    }
}
