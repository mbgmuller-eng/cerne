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
use App\Livewire\Health\Gym\GymWorkoutShow;
use App\Models\FinancialProfile;
use App\Models\GymExerciseCatalog;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymLibraryImporter;
use App\Services\GymPlanService;
use App\Services\GymWorkoutGenerator;
use App\Support\GymGeneratorInput;
use App\Support\ProfileContext;
use Database\Seeders\GymExerciseCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Biblioteca pública de exercícios no catálogo: passo a passo nos que já existem, cadastro dos novos com as duas fotos, sem
 * duplicar na segunda rodada e sem entrar no treino automático.
 */
class GymLibraryImportTest extends TestCase
{
    use RefreshDatabase;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('cerne.gym_images.disk'));
        $this->pasta = sys_get_temp_dir().'/cerne-biblioteca-'.uniqid();
        mkdir($this->pasta.'/Novo_Exercicio', 0777, true);

        foreach (['0.jpg', '1.jpg'] as $quadro) {
            $im = imagecreatetruecolor(40, 30);
            imagejpeg($im, $this->pasta.'/Novo_Exercicio/'.$quadro);
            imagedestroy($im);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pasta.'/*/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        foreach (glob($this->pasta.'/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->pasta);

        parent::tearDown();
    }

    private function catalogo(string $nome, array $extra = []): GymExerciseCatalog
    {
        return GymExerciseCatalog::create($extra + [
            'profile_id' => null, 'name' => $nome, 'muscle_group' => GymMuscleGroup::Chest, 'measure_type' => GymMeasureType::LoadReps, 'equipment_hint' => 'Barra livre',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function biblioteca(): array
    {
        return [
            ['match' => 'Supino Reto com Barra', 'source_id' => 'Barbell_Bench_Press', 'instructions' => ['Deite-se no banco.', 'Desça a barra até o peito.']],
            ['match' => 'Exercício que não existe', 'source_id' => 'X', 'instructions' => ['Passo.']],
            ['source_id' => 'Novo_Exercicio', 'name' => 'Remada Nova', 'muscle_group' => 'back', 'measure_type' => 'load_reps', 'equipment_hint' => 'Cabo/polia', 'category' => 'forca',
                'instructions' => ['Puxe até o abdômen.', 'Volte devagar.']],
        ];
    }

    public function test_exercicio_existente_ganha_o_passo_a_passo_e_o_novo_e_cadastrado_com_as_duas_fotos(): void
    {
        $existente = $this->catalogo('Supino Reto com Barra', ['notes' => 'Pegada pouco mais larga que os ombros']);

        $r = app(GymLibraryImporter::class)->import($this->biblioteca(), $this->pasta);

        self::assertSame(1, $r['instrucoes']);
        self::assertSame(1, $r['criados']);
        self::assertSame(1, $r['fotos']);
        self::assertSame(['Exercício que não existe'], $r['sem_par']);

        $existente->refresh();
        self::assertSame(['Deite-se no banco.', 'Desça a barra até o peito.'], $existente->instructions);
        self::assertSame('Pegada pouco mais larga que os ombros', $existente->notes, 'as observações do Cerne não são tocadas');
        self::assertNull($existente->image_path, 'foto não é criada para exercício que já existia');

        $novo = GymExerciseCatalog::query()->where('name', 'Remada Nova')->firstOrFail();
        self::assertSame(GymMuscleGroup::Back, $novo->muscle_group);
        self::assertSame('Cabo/polia', $novo->equipment_hint);
        self::assertSame('Novo_Exercicio', $novo->source_id);
        self::assertNull($novo->profile_id, 'é do catálogo compartilhado');
        self::assertTrue($novo->hasAnimatedImage());
        Storage::disk(config('cerne.gym_images.disk'))->assertExists([$novo->image_path, $novo->image_path_2]);
    }

    public function test_rodar_de_novo_nao_duplica_nem_troca_foto_nem_reescreve_o_texto(): void
    {
        $this->catalogo('Supino Reto com Barra');
        $importador = app(GymLibraryImporter::class);
        $importador->import($this->biblioteca(), $this->pasta);

        $novo = GymExerciseCatalog::query()->where('name', 'Remada Nova')->firstOrFail();
        $fotoAntes = $novo->image_path;
        $novo->update(['instructions' => ['Texto ajustado pelo admin.']]);

        $r = $importador->import($this->biblioteca(), $this->pasta);

        self::assertSame(0, $r['criados']);
        self::assertSame(0, $r['fotos']);
        self::assertSame(1, GymExerciseCatalog::query()->where('name', 'Remada Nova')->count());
        self::assertSame($fotoAntes, $novo->fresh()->image_path);
        self::assertSame(['Texto ajustado pelo admin.'], $novo->fresh()->instructions, 'o ajuste do admin sobrevive');

        $importador->import($this->biblioteca(), $this->pasta, sobrescrever: true);
        self::assertSame(['Puxe até o abdômen.', 'Volte devagar.'], $novo->fresh()->instructions, 'com --force reescreve');
    }

    public function test_simulacao_nao_grava_nada(): void
    {
        $this->catalogo('Supino Reto com Barra');

        $r = app(GymLibraryImporter::class)->import($this->biblioteca(), $this->pasta, simular: true);

        self::assertSame(1, $r['criados']);
        self::assertSame(1, GymExerciseCatalog::query()->count());
        self::assertNull(GymExerciseCatalog::query()->first()->instructions);
        self::assertSame([], Storage::disk(config('cerne.gym_images.disk'))->allFiles());
    }

    public function test_exercicio_com_o_mesmo_nome_e_adotado_em_vez_de_duplicado(): void
    {
        $antigo = $this->catalogo('Remada Nova');

        $r = app(GymLibraryImporter::class)->import($this->biblioteca(), $this->pasta);

        self::assertSame(0, $r['criados']);
        self::assertSame(1, $r['adotados']);
        self::assertSame('Novo_Exercicio', $antigo->fresh()->source_id);
        self::assertTrue($antigo->fresh()->hasAnimatedImage(), 'ganha as fotos que não tinha');
        self::assertSame(1, GymExerciseCatalog::query()->where('name', 'Remada Nova')->count());
    }

    public function test_sem_foto_na_pasta_cadastra_o_exercicio_e_avisa(): void
    {
        $r = app(GymLibraryImporter::class)->import([['source_id' => 'Sem_Foto', 'name' => 'Sem Foto', 'muscle_group' => 'arms', 'measure_type' => 'load_reps', 'instructions' => ['Passo.']]], $this->pasta);

        self::assertSame(1, $r['criados']);
        self::assertSame(['Sem Foto'], $r['sem_foto']);
        self::assertNull(GymExerciseCatalog::query()->where('name', 'Sem Foto')->firstOrFail()->image_path);
    }

    public function test_o_comando_simula_e_grava(): void
    {
        $arquivo = $this->pasta.'/biblioteca.json';
        file_put_contents($arquivo, json_encode($this->biblioteca(), JSON_UNESCAPED_UNICODE));
        $this->catalogo('Supino Reto com Barra');

        $this->artisan('cerne:gym-library-import', ['--file' => $arquivo, '--images' => $this->pasta, '--dry-run' => true])->assertSuccessful();
        self::assertSame(1, GymExerciseCatalog::query()->count());

        $this->artisan('cerne:gym-library-import', ['--file' => $arquivo, '--images' => $this->pasta])->assertSuccessful();
        self::assertSame(2, GymExerciseCatalog::query()->count());

        @unlink($arquivo);
    }

    public function test_o_arquivo_da_biblioteca_do_repositorio_e_valido(): void
    {
        $linhas = json_decode(file_get_contents(database_path('data/gym-exercise-library.json')), true, flags: JSON_THROW_ON_ERROR);
        $catalogo = array_column(json_decode(file_get_contents(database_path('data/gym-exercise-catalog.json')), true), 'name');
        $novos = array_filter($linhas, fn (array $l) => isset($l['name']));
        $ids = array_column($novos, 'source_id');

        self::assertGreaterThan(700, count($novos));
        self::assertSame(count($ids), count(array_unique($ids)), 'source_id único');

        $nomes = array_map(fn (array $l) => GymExerciseCatalog::normalizeName($l['name']), $novos);
        self::assertSame(count($nomes), count(array_unique($nomes)), 'nenhum nome repetido entre os novos');
        self::assertSame([], array_values(array_intersect($nomes, array_map([GymExerciseCatalog::class, 'normalizeName'], $catalogo))), 'nenhum novo repete um exercício do catálogo');

        // A biblioteca de origem deixa meia dúzia de exercícios sem texto: aparecem sem o "Como fazer", mas com foto.
        self::assertLessThan(10, count(array_filter($linhas, fn (array $l) => empty($l['instructions']))));

        foreach ($linhas as $l) {
            self::assertIsArray($l['instructions']);

            if (isset($l['name'])) {
                self::assertNotNull(GymMuscleGroup::tryFrom($l['muscle_group']));
                self::assertNotNull(GymMeasureType::tryFrom($l['measure_type']));
                self::assertLessThanOrEqual(120, mb_strlen($l['name']));
            } else {
                self::assertContains($l['match'], $catalogo, 'a correspondência aponta para um exercício do catálogo');
            }
        }
    }

    // --- Onde aparece e o que não muda --------------------------------------------------------------------------------------

    private function entrar(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);
    }

    public function test_o_passo_a_passo_aparece_no_treino_da_pessoa_pelo_nome_do_exercicio(): void
    {
        $this->entrar();
        $this->catalogo('Supino Reto com Barra', ['instructions' => ['Deite-se no banco.', 'Desça a barra até o peito.']]);
        $this->catalogo('Rosca Direta', ['muscle_group' => GymMuscleGroup::Arms]);

        $service = app(GymPlanService::class);
        $fase = $service->createPlan('Fase 1');
        $treino = $service->addWorkout($fase, 'Treino A', null);
        $meta = ['target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 12, 'rest_seconds' => 60];
        $service->addExercise($treino, $service->findOrCreateExercise('supino reto com barra', GymMuscleGroup::Chest, GymMeasureType::LoadReps), $meta);
        $service->addExercise($treino, $service->findOrCreateExercise('Rosca Direta', GymMuscleGroup::Arms, GymMeasureType::LoadReps), $meta);

        Livewire::test(GymWorkoutShow::class, ['workout' => $treino->id])
            ->assertSee('Como fazer')
            ->assertSee('Desça a barra até o peito.')
            ->assertSee('Orientação geral');

        self::assertSame(['Deite-se no banco.', 'Desça a barra até o peito.'], GymExerciseCatalog::instructionsFor('Supino Reto com Barra'));
        self::assertNull(GymExerciseCatalog::instructionsFor('Rosca Direta'), 'sem texto, nada aparece');
        self::assertNull(GymExerciseCatalog::instructionsFor('Não existe'));
    }

    public function test_exercicio_desativado_no_catalogo_nao_mostra_o_passo_a_passo(): void
    {
        $this->catalogo('Supino Reto com Barra', ['instructions' => ['Passo.'], 'is_active' => false]);

        self::assertNull(GymExerciseCatalog::instructionsFor('Supino Reto com Barra'));
    }

    public function test_o_treino_automatico_so_sorteia_da_lista_curada_mas_a_troca_enxerga_a_biblioteca(): void
    {
        $this->entrar();
        $this->seed(GymExerciseCatalogSeeder::class);

        // Um exercício importado que o gerador, sozinho, escolheria (peito, aparelho, básico): precisa ficar de fora do sorteio.
        $importado = $this->catalogo('Supino Reto com Barra e Pegada Larga', ['in_auto_workout' => false, 'source_id' => 'Wide-Grip_Barbell_Bench_Press']);

        $entrada = new GymGeneratorInput(GymGoal::Hypertrophy, GymLevel::Intermediate, 4, 90, GymEquipmentAccess::FullGym, [], 0);
        $gerador = app(GymWorkoutGenerator::class);

        $nomes = collect($gerador->generate($entrada)['workouts'])->flatMap(fn ($t) => $t['exercises'])->pluck('name');
        self::assertFalse($nomes->contains($importado->name), 'importado não entra no sorteio');

        self::assertNotNull($gerador->rowFor($importado->id, $entrada), 'mas a pessoa pode escolhê-lo na troca');
    }

    public function test_a_migration_nao_muda_o_que_ja_existia(): void
    {
        $this->seed(GymExerciseCatalogSeeder::class);

        self::assertSame(0, GymExerciseCatalog::query()->where('in_auto_workout', false)->count(), 'a lista curada continua toda no treino automático');
        self::assertSame(0, GymExerciseCatalog::query()->whereNotNull('source_id')->count());
    }
}
