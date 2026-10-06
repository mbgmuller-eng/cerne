<?php

namespace Tests\Feature;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Livewire\Admin\AdminExercises;
use App\Models\GymExerciseCatalog;
use App\Models\GymExerciseSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminExercisesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('cerne.gym_images.disk'));
    }

    private function disco()
    {
        return Storage::disk(config('cerne.gym_images.disk'));
    }

    private function admin(): User
    {
        return User::factory()->create(['is_platform_admin' => true]);
    }

    private function exercicio(string $nome = 'Remada Teste'): GymExerciseCatalog
    {
        return GymExerciseCatalog::create([
            'name' => $nome, 'muscle_group' => GymMuscleGroup::Back, 'measure_type' => GymMeasureType::LoadReps,
        ]);
    }

    private function enviarQuadros(GymExerciseCatalog $e): GymExerciseCatalog
    {
        Livewire::test(AdminExercises::class)->call('editar', $e->id)
            ->set('foto1', UploadedFile::fake()->image('a.jpg'))
            ->set('foto2', UploadedFile::fake()->image('b.jpg'))
            ->call('salvar')
            ->assertHasNoErrors();

        return $e->fresh();
    }

    public function test_quem_nao_e_admin_recebe_403(): void
    {
        $this->actingAs(User::factory()->consultant()->create());

        Livewire::test(AdminExercises::class)->assertStatus(403);
        $this->get(route('admin.exercises'))->assertForbidden();
    }

    public function test_admin_abre_a_tela_e_ve_o_catalogo(): void
    {
        $this->actingAs($this->admin());
        $this->exercicio('Remada Teste');

        $this->get(route('admin.exercises'))->assertOk()->assertSee('Exercícios')->assertSee('Remada Teste');
    }

    public function test_cria_exercicio_com_dois_quadros(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AdminExercises::class)
            ->call('novo')
            ->set('formName', 'Puxada Nova')
            ->set('formGroup', 'back')
            ->set('formEquipment', 'Polia')
            ->set('foto1', UploadedFile::fake()->image('a.jpg'))
            ->set('foto2', UploadedFile::fake()->image('b.jpg'))
            ->call('salvar')
            ->assertHasNoErrors()
            ->assertSet('showForm', false);

        $criado = GymExerciseCatalog::withoutTaxonomyScope()->where('name', 'Puxada Nova')->sole();
        self::assertNull($criado->profile_id);
        self::assertSame('Polia', $criado->equipment_hint);
        self::assertTrue($criado->hasAnimatedImage());
        $this->disco()->assertExists($criado->image_path);
        $this->disco()->assertExists($criado->image_path_2);
    }

    public function test_trocar_o_quadro_apaga_o_arquivo_antigo(): void
    {
        $this->actingAs($this->admin());
        $e = $this->enviarQuadros($this->exercicio());
        $antigo = $e->image_path;

        Livewire::test(AdminExercises::class)->call('editar', $e->id)
            ->set('foto1', UploadedFile::fake()->image('c.jpg'))->call('salvar');

        $novo = $e->fresh()->image_path;
        self::assertNotSame($antigo, $novo);
        $this->disco()->assertMissing($antigo);
        $this->disco()->assertExists($novo);
    }

    public function test_remover_quadro_1_promove_o_quadro_2(): void
    {
        $this->actingAs($this->admin());
        $e = $this->enviarQuadros($this->exercicio());
        [$q1, $q2] = [$e->image_path, $e->image_path_2];

        Livewire::test(AdminExercises::class)->call('editar', $e->id)->set('removerFoto1', true)->call('salvar');

        $e->refresh();
        self::assertSame($q2, $e->image_path);
        self::assertNull($e->image_path_2);
        $this->disco()->assertMissing($q1);
        $this->disco()->assertExists($q2);
    }

    public function test_remover_so_o_quadro_2(): void
    {
        $this->actingAs($this->admin());
        $e = $this->enviarQuadros($this->exercicio());
        $q2 = $e->image_path_2;

        Livewire::test(AdminExercises::class)->call('editar', $e->id)->set('removerFoto2', true)->call('salvar');

        self::assertNull($e->fresh()->image_path_2);
        self::assertNotNull($e->fresh()->image_path);
        $this->disco()->assertMissing($q2);
    }

    public function test_excluir_apaga_as_duas_fotos(): void
    {
        $this->actingAs($this->admin());
        $e = $this->enviarQuadros($this->exercicio());

        Livewire::test(AdminExercises::class)->call('excluir', $e->id);

        self::assertNull(GymExerciseCatalog::withoutTaxonomyScope()->find($e->id));
        $this->disco()->assertMissing($e->image_path);
        $this->disco()->assertMissing($e->image_path_2);
    }

    public function test_nome_duplicado_ignorando_acento_e_caixa_e_barrado(): void
    {
        $this->actingAs($this->admin());
        $this->exercicio('Remada Cavalinho');

        Livewire::test(AdminExercises::class)->call('novo')
            ->set('formName', 'remada CAVALINHO')
            ->call('salvar')
            ->assertHasErrors('formName');

        self::assertSame(1, GymExerciseCatalog::withoutTaxonomyScope()->where('name', 'like', '%avalinho%')->count());
    }

    public function test_editar_mantendo_o_proprio_nome_nao_e_duplicidade(): void
    {
        $this->actingAs($this->admin());
        $e = $this->exercicio('Remada Cavalinho');

        Livewire::test(AdminExercises::class)->call('editar', $e->id)
            ->set('formName', 'Remada Cavalinho Unilateral')
            ->call('salvar')->assertHasNoErrors();

        self::assertSame('Remada Cavalinho Unilateral', $e->fresh()->name);
    }

    public function test_foto_acima_do_limite_ou_que_nao_e_imagem_e_rejeitada(): void
    {
        $this->actingAs($this->admin());
        $limite = (int) config('cerne.gym_images.max_kb');

        Livewire::test(AdminExercises::class)->call('novo')
            ->set('formName', 'Qualquer')
            ->set('foto1', UploadedFile::fake()->image('grande.jpg')->size($limite + 1))
            ->set('foto2', UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'))
            ->call('salvar')
            ->assertHasErrors(['foto1', 'foto2']);

        self::assertSame(0, GymExerciseCatalog::withoutTaxonomyScope()->where('name', 'Qualquer')->count());
    }

    public function test_desativar_tira_o_exercicio_da_lista_de_quem_monta_o_treino(): void
    {
        $this->actingAs($this->admin());
        $e = $this->exercicio('Remada Teste');

        Livewire::test(AdminExercises::class)->call('alternarAtivo', $e->id);

        self::assertFalse($e->fresh()->is_active);
        self::assertSame(0, GymExerciseCatalog::query()->active()->where('name', 'Remada Teste')->count());
    }

    public function test_filtro_sem_foto_e_busca_sem_acento(): void
    {
        $this->actingAs($this->admin());
        $this->enviarQuadros($this->exercicio('Remada Com Foto'));
        $this->exercicio('Remada Sem Foto');

        Livewire::test(AdminExercises::class)
            ->set('somenteSemFoto', true)
            ->assertSee('Remada Sem Foto')->assertDontSee('Remada Com Foto')
            ->set('somenteSemFoto', false)
            ->set('busca', 'COM FOTÓ')
            ->assertSee('Remada Com Foto')->assertDontSee('Remada Sem Foto');
    }

    public function test_aprovar_sugestao_cria_a_entrada_compartilhada_com_foto_e_tira_da_fila(): void
    {
        $this->actingAs($this->admin());
        GymExerciseSuggestion::record('Rosca Martelo Inclinada', GymMuscleGroup::Arms, GymMeasureType::LoadReps);
        $sugestao = GymExerciseSuggestion::query()->sole();

        Livewire::test(AdminExercises::class)
            ->call('aprovarSugestao', $sugestao->id)
            ->assertSet('formName', 'Rosca Martelo Inclinada')
            ->assertSet('formGroup', 'arms')
            ->set('foto1', UploadedFile::fake()->image('a.jpg'))
            ->call('salvar')
            ->assertHasNoErrors();

        $criado = GymExerciseCatalog::withoutTaxonomyScope()->where('name', 'Rosca Martelo Inclinada')->sole();
        self::assertNull($criado->profile_id);
        self::assertNotNull($criado->image_path);
        self::assertNotNull($sugestao->fresh()->approved_at);
        self::assertSame(0, GymExerciseSuggestion::query()->pending()->count());
    }

    public function test_dispensar_sugestao_tira_da_fila_sem_criar_nada(): void
    {
        $this->actingAs($this->admin());
        GymExerciseSuggestion::record('Exercício Esquisito', GymMuscleGroup::Other, GymMeasureType::LoadReps);
        $sugestao = GymExerciseSuggestion::query()->sole();

        Livewire::test(AdminExercises::class)->call('dispensarSugestao', $sugestao->id);

        self::assertNotNull($sugestao->fresh()->dismissed_at);
        self::assertSame(0, GymExerciseSuggestion::query()->pending()->count());
        self::assertSame(0, GymExerciseCatalog::withoutTaxonomyScope()->where('name', 'Exercício Esquisito')->count());
    }

    public function test_fila_mostra_quantas_vezes_foi_pedido_sem_dizer_quem(): void
    {
        $this->actingAs($this->admin());
        GymExerciseSuggestion::record('Rosca Martelo Inclinada', GymMuscleGroup::Arms, GymMeasureType::LoadReps);
        GymExerciseSuggestion::record('rosca martelo inclinada', GymMuscleGroup::Arms, GymMeasureType::LoadReps);

        Livewire::test(AdminExercises::class)->assertSee('Rosca Martelo Inclinada')->assertSee('pedido 2 vezes');
    }
}
