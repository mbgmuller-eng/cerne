<?php

namespace Tests\Feature;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Livewire\Health\Gym\GymPlanEditor;
use App\Models\FinancialProfile;
use App\Models\GymExercise;
use App\Models\GymExerciseCatalog;
use App\Models\GymWorkout;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\GymPlanService;
use App\Support\ProfileContext;
use Database\Seeders\GymExerciseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Catálogo compartilhado de exercícios: referência genérica (nome, grupo,
 * foto), visível pra qualquer cliente ao montar o plano — NÃO é dado de
 * saúde pessoal, por isso não passa pelo PersonalHealthScope.
 */
class GymExerciseCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_cria_o_catalogo_e_e_idempotente(): void
    {
        $this->seed(GymExerciseCatalogSeeder::class);
        $total = GymExerciseCatalog::query()->count();
        self::assertGreaterThan(100, $total);
        self::assertTrue(GymExerciseCatalog::query()->whereNull('profile_id')->where('name', 'Supino Reto com Barra')->exists());

        $this->seed(GymExerciseCatalogSeeder::class);
        self::assertSame($total, GymExerciseCatalog::query()->count());
    }

    public function test_catalogo_e_visivel_a_qualquer_pessoa_autenticada_sem_perfil_ativo(): void
    {
        GymExerciseCatalog::create(['name' => 'Supino Reto com Barra', 'muscle_group' => GymMuscleGroup::Chest, 'measure_type' => GymMeasureType::LoadReps]);

        $this->actingAs(User::factory()->create());
        // Sem ProfileContext nenhum montado — mesmo assim o catálogo aparece: não é dado de perfil.
        self::assertSame(1, GymExerciseCatalog::query()->count());
    }

    public function test_rota_de_imagem_do_catalogo_nao_exige_dono(): void
    {
        Storage::fake(config('cerne.gym_images.disk'));
        $exercicio = GymExerciseCatalog::create([
            'name' => 'Supino Reto com Barra', 'muscle_group' => GymMuscleGroup::Chest, 'measure_type' => GymMeasureType::LoadReps,
            'image_path' => UploadedFile::fake()->image('a.jpg')->storeAs('academia-imagens/catalogo', 'a.jpg', config('cerne.gym_images.disk')),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('health.gym.catalog-image', $exercicio))
            ->assertOk();
    }

    public function test_rota_de_imagem_do_catalogo_404_sem_foto(): void
    {
        $exercicio = GymExerciseCatalog::create(['name' => 'X', 'muscle_group' => GymMuscleGroup::Chest, 'measure_type' => GymMeasureType::LoadReps]);

        $this->actingAs(User::factory()->create())
            ->get(route('health.gym.catalog-image', $exercicio))
            ->assertNotFound();
    }

    public function test_nome_do_catalogo_preenche_grupo_tipo_e_avisa_que_tem_foto(): void
    {
        [$perfil, $membro, $treino] = $this->prepararTreino();
        Storage::fake(config('cerne.gym_images.disk'));
        GymExerciseCatalog::create([
            'name' => 'Agachamento Frontal', 'muscle_group' => GymMuscleGroup::Legs, 'measure_type' => GymMeasureType::LoadReps,
            'equipment_hint' => 'Barra livre',
            'image_path' => UploadedFile::fake()->image('a.jpg')->storeAs('academia-imagens/catalogo', 'a.jpg', config('cerne.gym_images.disk')),
        ]);

        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $treino->id)
            ->set('exName', 'Agachamento Frontal')
            ->assertSet('exGroup', 'legs')
            ->assertSet('exEquipment', 'Barra livre')
            ->assertSee('Do catálogo')
            ->assertSee('já tem foto');
    }

    public function test_criar_a_partir_do_catalogo_copia_a_foto_sem_upload_manual(): void
    {
        [$perfil, $membro, $treino] = $this->prepararTreino();
        Storage::fake(config('cerne.gym_images.disk'));
        $catalogo = GymExerciseCatalog::create([
            'name' => 'Agachamento Frontal', 'muscle_group' => GymMuscleGroup::Legs, 'measure_type' => GymMeasureType::LoadReps,
            'image_path' => UploadedFile::fake()->image('a.jpg')->storeAs('academia-imagens/catalogo', 'a.jpg', config('cerne.gym_images.disk')),
            'image_path_2' => UploadedFile::fake()->image('b.jpg')->storeAs('academia-imagens/catalogo', 'b.jpg', config('cerne.gym_images.disk')),
        ]);

        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $treino->id)
            ->set('exName', 'Agachamento Frontal')
            ->call('saveExercise')
            ->assertHasNoErrors();

        $exercicio = GymExercise::query()->where('name', 'Agachamento Frontal')->firstOrFail();
        self::assertNotNull($exercicio->image_path);
        self::assertNotNull($exercicio->image_path_2);
        // Cópia de verdade — caminho diferente do original, os dois convivem.
        self::assertNotSame($catalogo->image_path, $exercicio->image_path);
        Storage::disk(config('cerne.gym_images.disk'))->assertExists($exercicio->image_path);
    }

    public function test_upload_manual_ganha_do_catalogo(): void
    {
        [$perfil, $membro, $treino] = $this->prepararTreino();
        Storage::fake(config('cerne.gym_images.disk'));
        GymExerciseCatalog::create([
            'name' => 'Agachamento Frontal', 'muscle_group' => GymMuscleGroup::Legs, 'measure_type' => GymMeasureType::LoadReps,
            'image_path' => UploadedFile::fake()->image('catalogo.jpg')->storeAs('academia-imagens/catalogo', 'catalogo.jpg', config('cerne.gym_images.disk')),
        ]);

        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $treino->id)
            ->set('exName', 'Agachamento Frontal')
            ->set('exImage', UploadedFile::fake()->image('minha.jpg'))
            ->call('saveExercise')
            ->assertHasNoErrors();

        $exercicio = GymExercise::query()->where('name', 'Agachamento Frontal')->firstOrFail();
        self::assertNull($exercicio->image_path_2); // só 1 quadro: foi upload manual, não o par do catálogo
    }

    public function test_reaproveitar_exercicio_existente_com_foto_nao_e_sobrescrito_pelo_catalogo(): void
    {
        [$perfil, $membro, $treino] = $this->prepararTreino();
        Storage::fake(config('cerne.gym_images.disk'));
        $service = app(GymPlanService::class);
        $existente = $service->findOrCreateExercise('Agachamento Frontal', GymMuscleGroup::Legs, GymMeasureType::LoadReps);
        $service->setExerciseImage($existente, UploadedFile::fake()->image('minha.jpg'));
        $fotoOriginal = $existente->image_path;

        GymExerciseCatalog::create([
            'name' => 'Agachamento Frontal', 'muscle_group' => GymMuscleGroup::Legs, 'measure_type' => GymMeasureType::LoadReps,
            'image_path' => UploadedFile::fake()->image('catalogo.jpg')->storeAs('academia-imagens/catalogo', 'catalogo.jpg', config('cerne.gym_images.disk')),
        ]);

        $outroTreino = $service->addWorkout($service->activePlan(), 'Treino B', null);
        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $outroTreino->id)
            ->set('exName', 'Agachamento Frontal')
            ->call('saveExercise')
            ->assertHasNoErrors();

        self::assertSame($fotoOriginal, $existente->fresh()->image_path);
    }

    public function test_nome_sem_correspondencia_no_catalogo_nao_mostra_dica(): void
    {
        [$perfil, $membro, $treino] = $this->prepararTreino();

        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $treino->id)
            ->set('exName', 'Um exercício que não existe em lugar nenhum')
            ->assertSet('catalogMatchId', null)
            ->assertDontSee('Do catálogo');
    }

    /** @return array{0: FinancialProfile, 1: ProfileMember, 2: GymWorkout} */
    private function prepararTreino(): array
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);

        $service = app(GymPlanService::class);
        $treino = $service->addWorkout($service->createPlan('Fase 1'), 'Treino A', null);

        return [$perfil, $membro, $treino];
    }
}
