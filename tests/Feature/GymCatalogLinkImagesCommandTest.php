<?php

namespace Tests\Feature;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\GymExerciseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * cerne:gym-catalog-link-images — mesmo esquema de cerne:gym-link-images,
 * mas pro catálogo compartilhado: sem --email, sem dono.
 */
class GymCatalogLinkImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.gym_images.disk'));
    }

    public function test_simula_e_depois_vincula(): void
    {
        GymExerciseCatalog::create(['name' => 'Supino Reto com Barra', 'muscle_group' => GymMuscleGroup::Chest, 'measure_type' => GymMeasureType::LoadReps]);
        $mapping = $this->mappingFile([['exercise' => 'Supino Reto com Barra', 'file' => $this->fakeJpeg(), 'file2' => $this->fakeJpeg()]]);

        $this->artisan('cerne:gym-catalog-link-images', ['mapping' => $mapping, '--dry-run' => true])
            ->expectsOutputToContain('SIMULAÇÃO')->assertSuccessful();

        $exercicio = GymExerciseCatalog::query()->firstOrFail();
        self::assertNull($exercicio->image_path);

        $this->artisan('cerne:gym-catalog-link-images', ['mapping' => $mapping])->assertSuccessful();

        $exercicio->refresh();
        self::assertNotNull($exercicio->image_path);
        self::assertNotNull($exercicio->image_path_2);
        Storage::disk(config('cerne.gym_images.disk'))->assertExists($exercicio->image_path);
    }

    public function test_nome_desconhecido_nao_grava_nada(): void
    {
        $mapping = $this->mappingFile([['exercise' => 'Não existe', 'file' => $this->fakeJpeg()]]);

        $this->artisan('cerne:gym-catalog-link-images', ['mapping' => $mapping])
            ->expectsOutputToContain('não existe no catálogo')
            ->assertFailed();
    }

    public function test_trocar_a_foto_apaga_a_antiga(): void
    {
        $exercicio = GymExerciseCatalog::create(['name' => 'Supino Reto com Barra', 'muscle_group' => GymMuscleGroup::Chest, 'measure_type' => GymMeasureType::LoadReps]);
        $mapping1 = $this->mappingFile([['exercise' => 'Supino Reto com Barra', 'file' => $this->fakeJpeg()]]);
        $this->artisan('cerne:gym-catalog-link-images', ['mapping' => $mapping1])->assertSuccessful();
        $antiga = $exercicio->fresh()->image_path;

        $mapping2 = $this->mappingFile([['exercise' => 'Supino Reto com Barra', 'file' => $this->fakeJpeg()]]);
        $this->artisan('cerne:gym-catalog-link-images', ['mapping' => $mapping2])->assertSuccessful();

        Storage::disk(config('cerne.gym_images.disk'))->assertMissing($antiga);
        self::assertNotSame($antiga, $exercicio->fresh()->image_path);
    }

    /** @param  list<array<string, string>>  $rows */
    private function mappingFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'catlink').'.json';
        file_put_contents($path, json_encode($rows));

        return $path;
    }

    private function fakeJpeg(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        file_put_contents($path, base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='));

        return $path;
    }
}
