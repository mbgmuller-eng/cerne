<?php

namespace Tests\Feature;

use App\Enums\GymLoadMode;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\Concerns\Auditable;
use App\Models\FinancialProfile;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\User;
use App\Support\ProfileContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Cerne Saúde › Academia é dado PESSOAL: só o dono enxerga — nem o
 * cônjuge, nem consultor, nem corretor (ver PersonalHealthScope). O
 * contrário do restante do app, onde o consultor vinculado vê tudo.
 */
class GymPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<class-string> */
    private const MODELS = [
        GymExercise::class,
        GymEquipment::class,
        GymPlan::class,
        GymWorkout::class,
        GymWorkoutExercise::class,
        GymSession::class,
        GymSetLog::class,
    ];

    public function test_dono_ve_o_proprio_treino(): void
    {
        [$perfil, $titular] = $this->criarCasal();
        $this->semear($perfil, $titular);

        app(ProfileContext::class)->set($perfil, $titular);

        foreach (self::MODELS as $model) {
            self::assertSame(1, $model::query()->count(), "{$model} deveria aparecer pro dono");
        }
    }

    public function test_conjuge_nao_ve_o_treino_do_outro(): void
    {
        [$perfil, $titular, $conjuge] = $this->criarCasal();
        $this->semear($perfil, $titular);

        app(ProfileContext::class)->set($perfil, $conjuge);

        foreach (self::MODELS as $model) {
            self::assertSame(0, $model::query()->count(), "{$model} vazou pro cônjuge");
        }
    }

    public function test_cada_um_do_casal_ve_so_o_seu(): void
    {
        [$perfil, $titular, $conjuge] = $this->criarCasal();
        $this->semear($perfil, $titular, 'Supino do titular');
        $this->semear($perfil, $conjuge, 'Supino da esposa');

        app(ProfileContext::class)->set($perfil, $conjuge);

        self::assertSame(['Supino da esposa'], GymExercise::query()->pluck('name')->all());
    }

    public function test_consultor_vinculado_nao_ve_nada(): void
    {
        [$perfil, $titular] = $this->criarCasal();
        $this->semear($perfil, $titular);

        // Mesmo contexto que SetProfileContext monta pra um profissional:
        // sem membro, asConsultant ligado.
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        foreach (self::MODELS as $model) {
            self::assertSame(0, $model::query()->count(), "{$model} vazou pro consultor");
        }
    }

    public function test_profissional_continua_sem_ver_nada_mesmo_com_membro_no_contexto(): void
    {
        [$perfil, $titular] = $this->criarCasal();
        $this->semear($perfil, $titular);

        app(ProfileContext::class)->set($perfil, $titular, asConsultant: true);

        foreach (self::MODELS as $model) {
            self::assertSame(0, $model::query()->count(), "{$model} vazou com asConsultant e membro");
        }
    }

    public function test_sem_perfil_ativo_nao_ha_nada(): void
    {
        [$perfil, $titular] = $this->criarCasal();
        $this->semear($perfil, $titular);

        app(ProfileContext::class)->clear();

        foreach (self::MODELS as $model) {
            self::assertSame(0, $model::query()->count());
        }
    }

    public function test_perfil_sem_membro_no_contexto_falha_fechado(): void
    {
        [$perfil, $titular] = $this->criarCasal();
        $this->semear($perfil, $titular);

        app(ProfileContext::class)->set($perfil, member: null);

        foreach (self::MODELS as $model) {
            self::assertSame(0, $model::query()->count());
        }
    }

    public function test_criar_carimba_o_membro_do_contexto(): void
    {
        [$perfil, $titular] = $this->criarCasal();
        app(ProfileContext::class)->set($perfil, $titular);

        $exercicio = GymExercise::create([
            'name' => 'Supino Reto Halteres',
            'muscle_group' => GymMuscleGroup::Chest,
            'measure_type' => GymMeasureType::LoadReps,
        ]);

        self::assertSame($titular->id, $exercicio->member_id);
        self::assertSame($perfil->id, $exercicio->profile_id);
    }

    public function test_criar_sem_membro_no_contexto_e_recusado(): void
    {
        [$perfil] = $this->criarCasal();
        app(ProfileContext::class)->set($perfil, member: null, asConsultant: true);

        $this->expectException(LogicException::class);

        GymExercise::create([
            'name' => 'Supino Reto Halteres',
            'muscle_group' => GymMuscleGroup::Chest,
            'measure_type' => GymMeasureType::LoadReps,
        ]);
    }

    /**
     * audit_logs é legível pelo consultor (FinancialProfilePolicy::
     * viewAuditLog). Um model de saúde com Auditable gravaria carga e
     * repetições lá — vazamento por outro caminho.
     */
    public function test_nenhum_model_de_academia_usa_auditable(): void
    {
        foreach (self::MODELS as $model) {
            self::assertNotContains(Auditable::class, class_uses_recursive($model), "{$model} não pode usar Auditable");
        }
    }

    public function test_toque_duplo_em_concluir_serie_nao_cria_serie_repetida(): void
    {
        [$perfil, $titular] = $this->criarCasal();
        $linhas = $this->semear($perfil, $titular);
        app(ProfileContext::class)->set($perfil, $titular);

        $this->expectException(QueryException::class);

        GymSetLog::create([
            'session_id' => $linhas['session']->id,
            'exercise_id' => $linhas['exercise']->id,
            'set_number' => 1, // o semear() já gravou a série 1
            'reps' => 12,
        ]);
    }

    public function test_total_em_kg_e_derivado_ao_salvar(): void
    {
        [$perfil, $titular] = $this->criarCasal();
        $linhas = $this->semear($perfil, $titular);
        app(ProfileContext::class)->set($perfil, $titular);

        $porLado = GymSetLog::create([
            'session_id' => $linhas['session']->id,
            'exercise_id' => $linhas['exercise']->id,
            'set_number' => 2,
            'reps' => 12,
            'load_value' => '15',
            'load_mode' => GymLoadMode::PerSide,
        ]);
        $maquina = GymSetLog::create([
            'session_id' => $linhas['session']->id,
            'exercise_id' => $linhas['exercise']->id,
            'set_number' => 3,
            'reps' => 12,
            'load_value' => '6',
            'load_mode' => GymLoadMode::StackPosition,
        ]);

        self::assertSame('30.00', $porLado->fresh()->load_total_kg);
        self::assertNull($maquina->fresh()->load_total_kg);
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember, 2: ProfileMember} */
    private function criarCasal(): array
    {
        $usuarioTitular = User::factory()->create();
        $perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuarioTitular->id]);
        $titular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuarioTitular->id]);

        $usuarioConjuge = User::factory()->create();
        $conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $usuarioConjuge->id]);

        return [$perfil, $titular, $conjuge];
    }

    /**
     * Uma linha de cada model, de um dono. Grava sem passar por
     * ProfileContext: o escopo só vale na leitura, e os ids vão explícitos.
     *
     * @return array{exercise: GymExercise, session: GymSession}
     */
    private function semear(FinancialProfile $perfil, ProfileMember $dono, string $nomeExercicio = 'Supino Reto Halteres'): array
    {
        $dados = ['profile_id' => $perfil->id, 'member_id' => $dono->id];

        $exercicio = GymExercise::create($dados + [
            'name' => $nomeExercicio,
            'muscle_group' => GymMuscleGroup::Chest,
            'measure_type' => GymMeasureType::LoadReps,
        ]);
        $equipamento = GymEquipment::create($dados + ['name' => 'Halteres '.$dono->id]);
        $plano = GymPlan::create($dados + ['name' => 'Fase 1']);
        $treino = GymWorkout::create($dados + ['plan_id' => $plano->id, 'name' => 'Treino A', 'position' => 1]);
        GymWorkoutExercise::create($dados + [
            'workout_id' => $treino->id,
            'exercise_id' => $exercicio->id,
            'position' => 1,
            'rest_seconds' => 60,
            'default_equipment_id' => $equipamento->id,
        ]);
        $sessao = GymSession::create($dados + ['workout_id' => $treino->id, 'performed_on' => now()->toDateString()]);
        GymSetLog::create($dados + [
            'session_id' => $sessao->id,
            'exercise_id' => $exercicio->id,
            'set_number' => 1,
            'reps' => 12,
            'load_value' => '14',
            'load_mode' => GymLoadMode::PerDumbbell,
            'equipment_id' => $equipamento->id,
            'completed_at' => now(),
        ]);

        return ['exercise' => $exercicio, 'session' => $sessao];
    }
}
