<?php

namespace Tests\Feature;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymPlanEditor;
use App\Livewire\Health\Gym\GymSessionRun;
use App\Models\FinancialProfile;
use App\Models\GymExercise;
use App\Models\GymExerciseCatalog;
use App\Models\GymSessionSwap;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymPlanService;
use App\Services\GymSessionService;
use App\Support\ProfileContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Trocar o exercício de um item do treino: a troca definitiva (fica no treino para as próximas vezes), a de "só por hoje"
 * e, principalmente, o histórico que não pode mudar junto.
 */
class GymExerciseSwapTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private GymWorkout $treino;

    private GymWorkoutExercise $supino;

    private GymWorkoutExercise $remada;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($perfil, $membro);
        Subscription::create([
            'user_id' => $this->usuario->id, 'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);

        foreach ([
            ['Supino Reto com Barra', 'chest', 'load_reps', 'Barra livre'], ['Supino Reto com Halteres', 'chest', 'load_reps', 'Halteres'],
            ['Supino na Máquina', 'chest', 'load_reps', 'Máquina'], ['Flexão de Braço', 'chest', 'load_reps', 'Peso do corpo'],
            ['Remada Baixa', 'back', 'load_reps', 'Cabo/polia baixa'], ['Puxada Frontal', 'back', 'load_reps', 'Cabo/polia alta'], ['Prancha Frontal', 'chest', 'duration', 'Peso do corpo'],
        ] as [$nome, $grupo, $tipo, $equip]) {
            GymExerciseCatalog::create(['name' => $nome, 'muscle_group' => $grupo, 'measure_type' => $tipo, 'equipment_hint' => $equip]);
        }

        $plano = $this->plano();
        $this->treino = $plano->addWorkout($plano->createPlan('Fase 1'), 'Treino A', null);
        $this->supino = $plano->addExercise($this->treino, $plano->findOrCreateExercise('Supino Reto com Barra', GymMuscleGroup::Chest, GymMeasureType::LoadReps),
            ['target_sets' => 4, 'target_reps_min' => 6, 'target_reps_max' => 10, 'rest_seconds' => 90, 'notes' => 'Cuidado com o ombro']);
        $this->remada = $plano->addExercise($this->treino, $plano->findOrCreateExercise('Remada Baixa', GymMuscleGroup::Back, GymMeasureType::LoadReps),
            ['target_sets' => 3, 'target_reps_min' => 10, 'target_reps_max' => 12, 'rest_seconds' => 60]);
    }

    private function plano(): GymPlanService
    {
        return app(GymPlanService::class);
    }

    private function sessoes(): GymSessionService
    {
        return app(GymSessionService::class);
    }

    private function catalogo(string $nome): string
    {
        return 'catalog:'.GymExerciseCatalog::query()->where('name', $nome)->value('id');
    }

    // --- Opções -------------------------------------------------------------------------------------------------------

    public function test_opcoes_sao_do_mesmo_grupo_sem_os_que_ja_estao_no_treino_e_sem_o_atual(): void
    {
        $nomes = array_column($this->plano()->swapOptions($this->supino), 'name');

        // Do mesmo grupo (peito); fora o atual e fora a Remada (outro grupo, e já está no treino).
        self::assertEqualsCanonicalizing(['Supino Reto com Halteres', 'Supino na Máquina', 'Flexão de Braço', 'Prancha Frontal'], $nomes);
        self::assertNotContains('Supino Reto com Barra', $nomes);
        self::assertNotContains('Remada Baixa', $nomes);
        self::assertNotContains('Puxada Frontal', $nomes, 'outro grupo só aparece buscando ou marcando "todos os grupos"');
    }

    public function test_exercicio_do_mesmo_tipo_de_medida_vem_antes_e_os_que_a_pessoa_ja_usa_vem_primeiro(): void
    {
        $meu = $this->plano()->findOrCreateExercise('Crucifixo Meu', GymMuscleGroup::Chest, GymMeasureType::LoadReps);

        $opcoes = $this->plano()->swapOptions($this->supino, todosGrupos: false);

        self::assertSame('own:'.$meu->id, $opcoes[0]['key'], 'o que ela já usa primeiro');
        self::assertSame('Prancha Frontal', end($opcoes)['name'] ?? null, 'tempo, num treino de carga, fica no fim');
    }

    public function test_buscar_por_nome_ou_equipamento_olha_todos_os_grupos_sem_acento(): void
    {
        $porNome = array_column($this->plano()->swapOptions($this->supino, 'PUXADA'), 'name');
        $porEquipamento = array_column($this->plano()->swapOptions($this->supino, 'MAQUINA'), 'name');
        $todosOsGrupos = array_column($this->plano()->swapOptions($this->supino, '', true), 'name');

        self::assertSame(['Puxada Frontal'], $porNome, 'a busca atravessa os grupos');
        self::assertSame(['Supino na Máquina'], $porEquipamento, 'sem acento nem maiúscula, e acha pelo equipamento');
        self::assertContains('Puxada Frontal', $todosOsGrupos);
        self::assertNotContains('Remada Baixa', $todosOsGrupos, 'o que já está no treino nunca é oferecido');
    }

    // --- Troca definitiva -----------------------------------------------------------------------------------------------

    public function test_troca_definitiva_mantem_meta_e_observacao_e_vale_nas_proximas_vezes(): void
    {
        [$novo, $equip] = $this->plano()->resolveSwapChoice($this->catalogo('Supino Reto com Halteres'));

        $this->plano()->swapExercise($this->supino, $novo, $equip);

        $item = $this->supino->fresh(['exercise', 'defaultEquipment']);
        self::assertSame('Supino Reto com Halteres', $item->exercise->name);
        self::assertSame(4, $item->target_sets);
        self::assertSame([6, 10, 90], [$item->target_reps_min, $item->target_reps_max, $item->rest_seconds]);
        self::assertSame('Cuidado com o ombro', $item->notes);
        self::assertSame('Halteres', $item->defaultEquipment->name, 'o equipamento passa a ser o do exercício novo');
        self::assertSame(1, GymExercise::query()->where('name', 'Supino Reto com Halteres')->count());
        self::assertSame(2, $this->treino->workoutExercises()->count(), 'troca, não acrescenta nem remove');
        self::assertSame($this->supino->position, $item->position);
    }

    public function test_troca_para_exercicio_em_tempo_ajusta_a_meta_e_de_volta_para_carga_tambem(): void
    {
        [$prancha] = $this->plano()->resolveSwapChoice($this->catalogo('Prancha Frontal'));
        $this->plano()->swapExercise($this->supino, $prancha);

        $item = $this->supino->fresh();
        self::assertSame(30, $item->target_duration_seconds);
        self::assertNull($item->rest_seconds);
        self::assertNull($item->target_reps_min);

        [$halteres] = $this->plano()->resolveSwapChoice($this->catalogo('Supino Reto com Halteres'));
        $this->plano()->swapExercise($item, $halteres);

        $item = $this->supino->fresh();
        self::assertNull($item->target_duration_seconds);
        self::assertSame(60, $item->rest_seconds);
        self::assertSame([8, 12], [$item->target_reps_min, $item->target_reps_max]);
    }

    public function test_o_historico_nao_muda_quando_o_exercicio_do_treino_e_trocado(): void
    {
        $sessao = $this->sessoes()->start($this->treino);
        $this->sessoes()->recordSet($sessao, $this->supino->load('exercise'), 1, ['load' => '60', 'mode' => 'total', 'reps' => 10]);
        $this->sessoes()->recordSet($sessao, $this->remada->load('exercise'), 1, ['load' => '40', 'mode' => 'total', 'reps' => 12]);
        $this->sessoes()->finish($sessao);

        [$halteres] = $this->plano()->resolveSwapChoice($this->catalogo('Supino Reto com Halteres'));
        $this->plano()->swapExercise($this->supino, $halteres);

        // O detalhe da sessão antiga mostra o que foi feito naquele dia, não o plano de hoje.
        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->assertSee('Supino Reto com Barra')->assertSee('60 kg')->assertSee('Remada Baixa')
            ->assertDontSee('Supino Reto com Halteres');

        self::assertSame(2, GymSetLog::query()->where('session_id', $sessao->id)->count());
    }

    public function test_recusa_trocar_exercicio_que_ja_tem_serie_no_treino_em_andamento_ou_que_ja_esta_no_treino(): void
    {
        $sessao = $this->sessoes()->start($this->treino);
        $this->sessoes()->recordSet($sessao, $this->supino->load('exercise'), 1, ['load' => '60', 'mode' => 'total', 'reps' => 10]);
        [$halteres] = $this->plano()->resolveSwapChoice($this->catalogo('Supino Reto com Halteres'));

        try {
            $this->plano()->swapExercise($this->supino, $halteres);
            self::fail('trocou exercício com série no treino em andamento');
        } catch (DomainException $e) {
            self::assertStringContainsString('em andamento', $e->getMessage());
        }

        // A remada ainda não tem série, mas o supino já é do treino.
        try {
            $this->plano()->swapExercise($this->remada, $this->supino->exercise);
            self::fail('trocou por exercício que já está no treino');
        } catch (DomainException $e) {
            self::assertStringContainsString('já está', $e->getMessage());
        }

        self::assertSame('Supino Reto com Barra', $this->supino->fresh('exercise')->exercise->name);
    }

    public function test_tela_do_plano_troca_e_avisa_que_ficou_salvo(): void
    {
        Livewire::test(GymPlanEditor::class)
            ->call('openSwap', $this->supino->id)
            ->assertSee('Supino na Máquina')
            ->call('swapTo', $this->catalogo('Supino na Máquina'))
            ->assertSet('showSwap', false)
            ->assertSee('já ficou salvo assim para as próximas vezes')
            ->assertSee('Supino na Máquina');

        self::assertSame('Supino na Máquina', $this->supino->fresh('exercise')->exercise->name);
    }

    public function test_tela_do_plano_aceita_exercicio_digitado_no_grupo_do_que_sai(): void
    {
        Livewire::test(GymPlanEditor::class)
            ->call('openSwap', $this->supino->id)
            ->set('swapCustomName', 'Supino Smith')->call('swapToCustom')->assertHasNoErrors();

        $novo = $this->supino->fresh('exercise')->exercise;
        self::assertSame('Supino Smith', $novo->name);
        self::assertSame(GymMuscleGroup::Chest, $novo->muscle_group);
    }

    // --- Na sessão: só por hoje e definitiva -------------------------------------------------------------------------------

    public function test_so_por_hoje_nao_muda_o_treino_e_as_series_vao_para_o_exercicio_de_hoje(): void
    {
        $sessao = $this->sessoes()->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->call('openSwap', $this->supino->id)
            ->set('swapScope', 'today')
            ->call('swapTo', $this->catalogo('Flexão de Braço'))
            ->assertSet('showSwap', false)
            ->assertSee('Flexão de Braço')
            ->set("draft.{$this->supino->id}.reps", '15')
            ->call('completeSet', $this->supino->id, 1);

        // O treino segue com o supino; a série foi gravada no exercício de hoje.
        self::assertSame('Supino Reto com Barra', $this->supino->fresh('exercise')->exercise->name);
        $flexao = GymExercise::query()->where('name', 'Flexão de Braço')->firstOrFail();
        self::assertSame(1, GymSetLog::query()->where('session_id', $sessao->id)->where('exercise_id', $flexao->id)->count());
        self::assertSame(1, GymSessionSwap::query()->where('session_id', $sessao->id)->count());

        // Depois de finalizar, o histórico mostra o que foi feito; o treino seguinte volta ao supino.
        $this->sessoes()->finish($sessao->fresh());
        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])->assertSee('Flexão de Braço')->assertDontSee('Supino Reto com Barra');
        $proxima = $this->sessoes()->start($this->treino);
        Livewire::test(GymSessionRun::class, ['session' => $proxima->id])->assertSee('Supino Reto com Barra')->assertDontSee('Flexão de Braço');
    }

    public function test_salvar_no_treino_pela_sessao_troca_o_item_e_vale_nas_proximas_vezes(): void
    {
        $sessao = $this->sessoes()->start($this->treino);

        Livewire::test(GymSessionRun::class, ['session' => $sessao->id])
            ->call('openSwap', $this->supino->id)
            ->assertSet('swapScope', 'plan')
            ->call('swapTo', $this->catalogo('Supino na Máquina'))
            ->assertSee('Supino na Máquina');

        self::assertSame('Supino na Máquina', $this->supino->fresh('exercise')->exercise->name);
        self::assertSame(0, GymSessionSwap::query()->count());
    }

    public function test_troca_definitiva_substitui_a_de_hoje_do_mesmo_item(): void
    {
        $sessao = $this->sessoes()->start($this->treino);
        $tela = Livewire::test(GymSessionRun::class, ['session' => $sessao->id]);

        $tela->call('openSwap', $this->supino->id)->set('swapScope', 'today')->call('swapTo', $this->catalogo('Flexão de Braço'));
        self::assertSame(1, GymSessionSwap::query()->count());

        $tela->call('openSwap', $this->supino->id)->set('swapScope', 'plan')->call('swapTo', $this->catalogo('Supino na Máquina'));

        self::assertSame(0, GymSessionSwap::query()->count());
        self::assertSame('Supino na Máquina', $this->supino->fresh('exercise')->exercise->name);
    }

    public function test_nao_troca_na_sessao_depois_da_primeira_serie_nem_por_exercicio_de_outro_item(): void
    {
        $sessao = $this->sessoes()->start($this->treino);
        $tela = Livewire::test(GymSessionRun::class, ['session' => $sessao->id]);

        $tela->set("draft.{$this->supino->id}.reps", '10')->call('completeSet', $this->supino->id, 1)
            ->call('openSwap', $this->supino->id)->assertSet('showSwap', false)->assertSee('Desfaça as séries antes de trocar');

        // Por hoje, a remada não pode virar o supino (as séries são guardadas por exercício).
        try {
            $this->sessoes()->swapToday($sessao, $this->remada, $this->supino->exercise);
            self::fail('aceitou exercício que já está em outro item');
        } catch (DomainException $e) {
            self::assertStringContainsString('já está', $e->getMessage());
        }
    }

    public function test_trocar_de_volta_para_o_original_desfaz_a_troca_de_hoje(): void
    {
        $sessao = $this->sessoes()->start($this->treino);
        [$flexao] = $this->plano()->resolveSwapChoice($this->catalogo('Flexão de Braço'));
        $this->sessoes()->swapToday($sessao, $this->supino, $flexao);
        self::assertSame(1, GymSessionSwap::query()->count());

        $this->sessoes()->swapToday($sessao, $this->supino, $this->supino->exercise);

        self::assertSame(0, GymSessionSwap::query()->count());
    }

    public function test_so_o_dono_troca_exercicio_de_um_treino(): void
    {
        $outro = User::factory()->create();
        $perfilB = FinancialProfile::factory()->create(['owner_user_id' => $outro->id]);
        $membroB = ProfileMember::factory()->create(['profile_id' => $perfilB->id, 'user_id' => $outro->id]);
        $this->actingAs($outro);
        app(ProfileContext::class)->set($perfilB, $membroB);
        Subscription::create(['user_id' => $outro->id, 'kind' => SubscriptionKind::Direct, 'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now()]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(GymPlanEditor::class)->call('openSwap', $this->supino->id);
    }
}
