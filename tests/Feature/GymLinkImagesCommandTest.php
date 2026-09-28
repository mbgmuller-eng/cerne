<?php

namespace Tests\Feature;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\FinancialProfile;
use App\Models\GymExercise;
use App\Models\GymWorkout;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\GymPlanService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * cerne:gym-link-images — vincula fotos de referência já baixadas (fora
 * do app) aos exercícios de UMA pessoa, validando tudo antes de gravar.
 */
class GymLinkImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;
    private GymExercise $supino;
    private GymExercise $agachamento;
    private string $img1;
    private string $img2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.gym_images.disk'));

        $this->usuario = User::factory()->create(['email' => 'atleta@cerne.test']);
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $this->usuario->id]);
        app(ProfileContext::class)->set($perfil, $membro);

        $service = app(GymPlanService::class);
        $treino = $service->addWorkout($service->createPlan('Fase 1'), 'Treino A', null);
        $this->supino = $service->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps);
        $this->agachamento = $service->findOrCreateExercise('Agachamento', GymMuscleGroup::Legs, GymMeasureType::LoadReps);
        $service->addExercise($treino, $this->supino, ['target_sets' => 3]);
        $service->addExercise($treino, $this->agachamento, ['target_sets' => 3]);

        $this->img1 = $this->fakeJpeg('frame1');
        $this->img2 = $this->fakeJpeg('frame2');
    }

    public function test_simula_e_depois_vincula_um_ou_dois_quadros(): void
    {
        $mapping = $this->mappingFile([
            ['exercise' => 'Supino', 'file' => $this->img1, 'file2' => $this->img2],
            ['exercise' => 'Agachamento', 'file' => $this->img1],
        ]);

        $this->artisan('cerne:gym-link-images', ['mapping' => $mapping, '--email' => 'atleta@cerne.test', '--dry-run' => true])
            ->expectsOutputToContain('SIMULAÇÃO')
            ->assertSuccessful();

        self::assertNull($this->supino->fresh()->image_path);

        $this->artisan('cerne:gym-link-images', ['mapping' => $mapping, '--email' => 'atleta@cerne.test'])
            ->assertSuccessful();

        $supino = $this->supino->fresh();
        $agachamento = $this->agachamento->fresh();
        self::assertNotNull($supino->image_path);
        self::assertNotNull($supino->image_path_2);
        self::assertTrue($supino->hasAnimatedImage());
        self::assertNotNull($agachamento->image_path);
        self::assertNull($agachamento->image_path_2);
        Storage::disk(config('cerne.gym_images.disk'))->assertExists($supino->image_path);
        Storage::disk(config('cerne.gym_images.disk'))->assertExists($supino->image_path_2);
    }

    public function test_nome_de_exercicio_desconhecido_nao_grava_nada(): void
    {
        $mapping = $this->mappingFile([
            ['exercise' => 'Supino', 'file' => $this->img1],
            ['exercise' => 'Exercício que não existe', 'file' => $this->img1],
        ]);

        $this->artisan('cerne:gym-link-images', ['mapping' => $mapping, '--email' => 'atleta@cerne.test'])
            ->expectsOutputToContain('não existe no catálogo')
            ->assertFailed();

        self::assertNull($this->supino->fresh()->image_path);
    }

    public function test_arquivo_local_inexistente_nao_grava_nada(): void
    {
        $mapping = $this->mappingFile([
            ['exercise' => 'Supino', 'file' => '/caminho/que/nao/existe.jpg'],
        ]);

        $this->artisan('cerne:gym-link-images', ['mapping' => $mapping, '--email' => 'atleta@cerne.test'])
            ->expectsOutputToContain('arquivo não encontrado')
            ->assertFailed();
    }

    public function test_conjuge_sem_perfil_proprio_recebe_pela_conta_dele(): void
    {
        // Já existe um perfil do titular criado no setUp; só marca como casal.
        $titular = $this->usuario->ownedProfiles()->first();
        $titular->update(['profile_type' => \App\Enums\ProfileType::Couple]);
        $conjugeUsuario = User::factory()->create(['email' => 'conjuge@cerne.test']);
        $conjugeMembro = ProfileMember::factory()->secondary()->create(['profile_id' => $titular->id, 'user_id' => $conjugeUsuario->id]);

        app(ProfileContext::class)->set($titular, $conjugeMembro);
        $service = app(GymPlanService::class);
        $treino = $service->addWorkout($service->createPlan('Fase da esposa'), 'Treino A', null);
        $exercicioDaEsposa = $service->findOrCreateExercise('Hip Thrust', GymMuscleGroup::Glutes, GymMeasureType::LoadReps);
        $service->addExercise($treino, $exercicioDaEsposa, ['target_sets' => 3]);

        $mapping = $this->mappingFile([['exercise' => 'Hip Thrust', 'file' => $this->img1]]);

        $this->artisan('cerne:gym-link-images', ['mapping' => $mapping, '--email' => 'conjuge@cerne.test'])
            ->assertSuccessful();

        self::assertNotNull($exercicioDaEsposa->fresh()->image_path);
        // E o titular não vê a foto vinculada na conta da esposa.
        app(ProfileContext::class)->set($titular, $this->usuario->ownedProfiles()->first()->memberFor($this->usuario));
        self::assertSame(0, GymExercise::query()->where('name', 'Hip Thrust')->count());
    }

    /** @param  list<array<string, string>>  $rows */
    private function mappingFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gymlink').'.json';
        file_put_contents($path, json_encode($rows));

        return $path;
    }

    private function fakeJpeg(string $seed): string
    {
        $path = tempnam(sys_get_temp_dir(), $seed).'.jpg';
        // Um JPEG mínimo válido (cabeçalho real), pra mime_content_type() reconhecer.
        file_put_contents($path, base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='));

        return $path;
    }
}
