<?php

namespace Tests\Feature;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Livewire\Health\Gym\GymPlanEditor;
use App\Models\FinancialProfile;
use App\Models\GymExerciseCatalog;
use App\Models\GymExerciseSuggestion;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\GymPlanService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fila de sugestões de exercício ao catálogo: alimentada pela tela de montar o
 * treino, ANÔNIMA (treino é dado de Saúde) e idempotente por índice único.
 */
class GymExerciseSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function entrarComoPessoa(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);
    }

    private function pedir(string $nome, bool $sugerir = true): void
    {
        app(GymPlanService::class)->findOrCreateExercise($nome, GymMuscleGroup::Arms, GymMeasureType::LoadReps, $sugerir);
    }

    public function test_tabela_nao_guarda_perfil_membro_nem_usuario(): void
    {
        foreach (Schema::getColumnListing('gym_exercise_suggestions') as $coluna) {
            self::assertDoesNotMatchRegularExpression('/profile|member|user|owner/', $coluna, "A coluna {$coluna} identificaria quem pediu.");
        }
    }

    public function test_pedido_novo_cria_sugestao_com_contador_1(): void
    {
        GymExerciseSuggestion::record('Rosca Zottman', GymMuscleGroup::Arms, GymMeasureType::LoadReps);

        $s = GymExerciseSuggestion::query()->sole();
        self::assertSame('Rosca Zottman', $s->name);
        self::assertSame(1, $s->times_suggested);
        self::assertSame(GymMuscleGroup::Arms, $s->muscle_group);
    }

    public function test_mesmo_nome_com_outra_grafia_so_incrementa(): void
    {
        GymExerciseSuggestion::record('Rosca Zottman', GymMuscleGroup::Arms, GymMeasureType::LoadReps);
        GymExerciseSuggestion::record('rosca zôttman', GymMuscleGroup::Arms, GymMeasureType::LoadReps);
        GymExerciseSuggestion::record('  ROSCA ZOTTMAN ', GymMuscleGroup::Forearms, GymMeasureType::LoadReps);

        $s = GymExerciseSuggestion::query()->sole();
        self::assertSame(3, $s->times_suggested);
        self::assertSame('Rosca Zottman', $s->name);
    }

    public function test_nome_que_ja_esta_no_catalogo_nao_gera_sugestao_mesmo_sem_acento(): void
    {
        GymExerciseCatalog::create(['name' => 'Elevação Pélvica', 'muscle_group' => GymMuscleGroup::Glutes, 'measure_type' => GymMeasureType::LoadReps]);

        GymExerciseSuggestion::record('elevacao pelvica', GymMuscleGroup::Glutes, GymMeasureType::LoadReps);

        self::assertSame(0, GymExerciseSuggestion::query()->count());
    }

    public function test_nome_dispensado_nao_volta_nem_conta(): void
    {
        GymExerciseSuggestion::record('Rosca Zottman', GymMuscleGroup::Arms, GymMeasureType::LoadReps);
        GymExerciseSuggestion::query()->sole()->dismiss();

        GymExerciseSuggestion::record('Rosca Zottman', GymMuscleGroup::Arms, GymMeasureType::LoadReps);

        self::assertSame(0, GymExerciseSuggestion::query()->pending()->count());
        self::assertSame(1, GymExerciseSuggestion::query()->sole()->times_suggested);
    }

    public function test_criar_exercicio_novo_pelo_servico_com_flag_sugere(): void
    {
        $this->entrarComoPessoa();

        $this->pedir('Rosca Zottman');
        // Reusar o MESMO exercício pessoal não é um pedido novo.
        $this->pedir('Rosca Zottman');

        self::assertSame(1, GymExerciseSuggestion::query()->sole()->times_suggested);
    }

    public function test_importacao_sem_flag_nao_polui_a_fila(): void
    {
        $this->entrarComoPessoa();

        $this->pedir('Rosca Zottman', sugerir: false);

        self::assertSame(0, GymExerciseSuggestion::query()->count());
    }

    public function test_duas_pessoas_pedindo_o_mesmo_exercicio_somam_sem_identificar_ninguem(): void
    {
        $this->entrarComoPessoa();
        $this->pedir('Rosca Zottman');

        $this->entrarComoPessoa();
        $this->pedir('rosca zottman');

        $s = GymExerciseSuggestion::query()->sole();
        self::assertSame(2, $s->times_suggested);
        self::assertSame([], array_values(array_intersect(array_keys($s->getAttributes()), ['profile_id', 'member_id', 'user_id'])));
    }

    public function test_tela_de_montar_treino_gera_a_sugestao_ao_salvar_exercicio_novo(): void
    {
        $this->entrarComoPessoa();
        $service = app(GymPlanService::class);
        $treino = $service->addWorkout($service->createPlan('Fase 1'), 'Treino A', null);

        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $treino->id)
            ->set('exName', 'Rosca Zottman')
            ->set('exGroup', 'arms')
            ->set('exType', 'load_reps')
            ->set('exSets', '3')
            ->set('exRepsMin', '8')
            ->call('saveExercise')
            ->assertHasNoErrors();

        self::assertSame('Rosca Zottman', GymExerciseSuggestion::query()->sole()->name);
    }

    public function test_exercicio_do_catalogo_na_tela_nao_gera_sugestao(): void
    {
        $this->entrarComoPessoa();
        GymExerciseCatalog::create(['name' => 'Elevação Pélvica', 'muscle_group' => GymMuscleGroup::Glutes, 'measure_type' => GymMeasureType::LoadReps]);
        $service = app(GymPlanService::class);
        $treino = $service->addWorkout($service->createPlan('Fase 1'), 'Treino A', null);

        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $treino->id)
            ->set('exName', 'elevacao pelvica')
            ->set('exSets', '3')
            ->set('exRepsMin', '8')
            ->call('saveExercise')
            ->assertHasNoErrors();

        self::assertSame(0, GymExerciseSuggestion::query()->count());
    }
}
