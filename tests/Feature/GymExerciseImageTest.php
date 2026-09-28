<?php

namespace Tests\Feature;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Livewire\Health\Gym\GymPlanEditor;
use App\Models\FinancialProfile;
use App\Models\GymExercise;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\GymPlanService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Foto do exercício: fica no disco PRIVADO (mesma regra dos documentos
 * importados) e só é servida pra quem o PersonalHealthScope deixaria ver.
 */
class GymExerciseImageTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;
    private FinancialProfile $perfil;
    private ProfileMember $membro;
    private GymWorkout $treino;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.gym_images.disk'));

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $this->treino = app(GymPlanService::class)->addWorkout(app(GymPlanService::class)->createPlan('Fase 1'), 'Treino A', null);
    }

    public function test_criar_exercicio_com_foto_grava_no_disco_e_no_catalogo(): void
    {
        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $this->treino->id)
            ->set('exName', 'Supino')
            ->set('exImage', UploadedFile::fake()->image('supino.jpg'))
            ->call('saveExercise')
            ->assertHasNoErrors();

        $exercicio = GymExercise::query()->firstOrFail();
        self::assertNotNull($exercicio->image_path);
        Storage::disk(config('cerne.gym_images.disk'))->assertExists($exercicio->image_path);
        self::assertStringContainsString('/imagem', $exercicio->imageUrl());
    }

    public function test_editar_troca_a_foto_e_apaga_a_antiga(): void
    {
        $item = $this->criarExercicioComFoto();
        $antiga = $item->exercise->image_path;

        Livewire::test(GymPlanEditor::class)
            ->call('editExercise', $item->id)
            ->set('exImage', UploadedFile::fake()->image('nova.jpg'))
            ->call('saveExercise')
            ->assertHasNoErrors();

        $item->exercise->refresh();
        self::assertNotNull($item->exercise->image_path);
        self::assertNotSame($antiga, $item->exercise->image_path);
        Storage::disk(config('cerne.gym_images.disk'))->assertMissing($antiga);
        Storage::disk(config('cerne.gym_images.disk'))->assertExists($item->exercise->image_path);
    }

    public function test_remover_foto_apaga_do_disco_e_limpa_o_caminho(): void
    {
        $item = $this->criarExercicioComFoto();
        $caminho = $item->exercise->image_path;

        Livewire::test(GymPlanEditor::class)
            ->call('editExercise', $item->id)
            ->set('removeImage', true)
            ->call('saveExercise')
            ->assertHasNoErrors();

        self::assertNull($item->exercise->fresh()->image_path);
        Storage::disk(config('cerne.gym_images.disk'))->assertMissing($caminho);
        self::assertNull($item->exercise->fresh()->imageUrl());
    }

    public function test_editar_sem_mexer_na_foto_mantem_a_mesma(): void
    {
        $item = $this->criarExercicioComFoto();
        $caminho = $item->exercise->image_path;

        Livewire::test(GymPlanEditor::class)
            ->call('editExercise', $item->id)
            ->set('exSets', '5')
            ->call('saveExercise')
            ->assertHasNoErrors();

        self::assertSame($caminho, $item->exercise->fresh()->image_path);
    }

    public function test_arquivo_que_nao_e_imagem_e_recusado(): void
    {
        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $this->treino->id)
            ->set('exName', 'Supino')
            ->set('exImage', UploadedFile::fake()->create('laudo.pdf', 100, 'application/pdf'))
            ->call('saveExercise')
            ->assertHasErrors(['exImage']);

        self::assertSame(0, GymExercise::query()->count());
    }

    public function test_foto_grande_demais_e_recusada(): void
    {
        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $this->treino->id)
            ->set('exName', 'Supino')
            ->set('exImage', UploadedFile::fake()->image('grande.jpg')->size(config('cerne.gym_images.max_kb') + 500))
            ->call('saveExercise')
            ->assertHasErrors(['exImage']);
    }

    public function test_sem_foto_a_imagem_do_catalogo_continua_nula(): void
    {
        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $this->treino->id)
            ->set('exName', 'Supino')
            ->call('saveExercise')
            ->assertHasNoErrors();

        $exercicio = GymExercise::query()->firstOrFail();
        self::assertNull($exercicio->image_path);
        self::assertNull($exercicio->imageUrl());
    }

    public function test_rota_serve_a_foto_para_o_dono(): void
    {
        $item = $this->criarExercicioComFoto();

        $resposta = $this->get($item->exercise->imageUrl());

        $resposta->assertOk();
        self::assertStringStartsWith('image/', $resposta->headers->get('Content-Type'));
    }

    public function test_rota_nao_serve_a_foto_para_o_conjuge_nem_para_o_consultor(): void
    {
        $item = $this->criarExercicioComFoto();
        $url = $item->exercise->imageUrl();

        $conjuge = ProfileMember::factory()->secondary()->create([
            'profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id,
        ]);
        $this->actingAs($conjuge->user)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get($url)
            ->assertNotFound();

        $consultor = User::factory()->consultant()->create();
        \App\Models\ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuario->id,
            'status' => \App\Enums\ConsultantClientStatus::Active,
        ]);
        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get($url)
            ->assertNotFound();
    }

    public function test_rota_devolve_404_sem_foto_cadastrada(): void
    {
        $exercicio = app(GymPlanService::class)->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps);

        $this->get(route('health.gym.exercise-image', $exercicio))->assertNotFound();
    }

    public function test_importador_nao_recebe_foto_e_nao_quebra(): void
    {
        // O importador de histórico (bloco 5) não lida com fotos: garante
        // que um exercício sem imagem continua funcionando normalmente.
        $exercicio = app(GymPlanService::class)->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps);

        self::assertNull($exercicio->imageUrl());
    }

    private function criarExercicioComFoto(): GymWorkoutExercise
    {
        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $this->treino->id)
            ->set('exName', 'Supino')
            ->set('exImage', UploadedFile::fake()->image('supino.jpg'))
            ->call('saveExercise');

        return GymWorkoutExercise::query()->with('exercise')->firstOrFail();
    }
}
