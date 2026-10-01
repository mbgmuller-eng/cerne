<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymHome;
use App\Livewire\Health\Gym\GymPlanEditor;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\GymEquipment;
use App\Models\GymExercise;
use App\Models\GymPlan;
use App\Models\GymSession;
use App\Models\GymWorkout;
use App\Models\GymWorkoutExercise;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymPlanService;
use App\Support\ProfileContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cerne Saúde › Academia — montagem do plano (fase, treinos, exercícios) e
 * o acesso às telas. A privacidade das linhas em si está em GymPrivacyTest.
 */
class GymPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_fase_encerra_a_anterior_e_mantem_so_uma_ativa(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);

        $primeira = $servico->createPlan('Fase 1');
        $segunda = $servico->createPlan('Fase 2');

        self::assertFalse($primeira->fresh()->is_active);
        self::assertNotNull($primeira->fresh()->ended_on);
        self::assertTrue($segunda->fresh()->is_active);
        self::assertSame($segunda->id, $servico->activePlan()->id);
        self::assertSame(2, GymPlan::query()->count());
    }

    public function test_treinos_recebem_posicao_em_sequencia(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $plano = $servico->createPlan('Fase 1');

        $a = $servico->addWorkout($plano, 'Treino A', null);
        $b = $servico->addWorkout($plano, 'Treino B', 'Costas e bíceps');

        self::assertSame([1, 2], [$a->position, $b->position]);
        self::assertSame('Costas e bíceps', $b->focus);
    }

    public function test_mover_treino_troca_as_posicoes_sem_violar_o_indice_unico(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $plano = $servico->createPlan('Fase 1');
        $a = $servico->addWorkout($plano, 'Treino A', null);
        $b = $servico->addWorkout($plano, 'Treino B', null);

        $servico->moveWorkout($b, -1);

        self::assertSame(2, $a->fresh()->position);
        self::assertSame(1, $b->fresh()->position);

        // No limite não faz nada.
        $servico->moveWorkout($b->fresh(), -1);
        self::assertSame(1, $b->fresh()->position);
    }

    public function test_excluir_treino_renumera_e_recusa_quando_ja_tem_sessao(): void
    {
        [$perfil, $membro] = $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $plano = $servico->createPlan('Fase 1');
        $a = $servico->addWorkout($plano, 'Treino A', null);
        $b = $servico->addWorkout($plano, 'Treino B', null);
        $c = $servico->addWorkout($plano, 'Treino C', null);

        $servico->deleteWorkout($a);
        self::assertSame([1, 2], [$b->fresh()->position, $c->fresh()->position]);

        GymSession::create(['workout_id' => $b->id, 'performed_on' => now()->toDateString()]);

        $this->expectException(DomainException::class);
        $servico->deleteWorkout($b->fresh());
    }

    public function test_exercicio_com_o_mesmo_nome_reaproveita_o_do_catalogo(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);

        $um = $servico->findOrCreateExercise('Supino reto', GymMuscleGroup::Chest, GymMeasureType::LoadReps);
        $dois = $servico->findOrCreateExercise('  Supino reto ', GymMuscleGroup::Chest, GymMeasureType::LoadReps);

        self::assertSame($um->id, $dois->id);
        self::assertSame(1, GymExercise::query()->count());
        self::assertNull($servico->findOrCreateEquipment('   '));
        self::assertSame(
            $servico->findOrCreateEquipment('Halteres')->id,
            $servico->findOrCreateEquipment('Halteres')->id,
        );
    }

    public function test_exercicio_em_tempo_nao_guarda_repeticoes_nem_pausa(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $treino = $servico->addWorkout($servico->createPlan('Fase 1'), 'Treino A', null);
        $prancha = $servico->findOrCreateExercise('Prancha', GymMuscleGroup::Core, GymMeasureType::Duration);

        $item = $servico->addExercise($treino, $prancha, [
            'target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 12,
            'target_duration_seconds' => 45, 'rest_seconds' => 60,
        ]);

        self::assertNull($item->target_reps_min);
        self::assertNull($item->rest_seconds);
        self::assertSame(45, $item->target_duration_seconds);
    }

    public function test_mover_e_remover_exercicio_mantem_a_ordem_sem_buracos(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $treino = $servico->addWorkout($servico->createPlan('Fase 1'), 'Treino A', null);
        $itens = [];
        foreach (['Supino', 'Crucifixo', 'Tríceps'] as $nome) {
            $exercicio = $servico->findOrCreateExercise($nome, GymMuscleGroup::Chest, GymMeasureType::LoadReps);
            $itens[] = $servico->addExercise($treino, $exercicio, ['target_sets' => 3]);
        }

        $servico->moveExercise($itens[2], -1);
        self::assertSame(2, $itens[2]->fresh()->position);
        self::assertSame(3, $itens[1]->fresh()->position);

        $servico->removeExercise($itens[0]->fresh());
        self::assertSame([1, 2], GymWorkoutExercise::query()->orderBy('position')->pluck('position')->all());
    }

    public function test_proximo_treino_segue_a_rotacao_e_volta_ao_primeiro(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $plano = $servico->createPlan('Fase 1');
        $a = $servico->addWorkout($plano, 'Treino A', null);
        $b = $servico->addWorkout($plano, 'Treino B', null);

        self::assertSame($a->id, $servico->nextWorkout($plano)->id);

        GymSession::create(['workout_id' => $a->id, 'performed_on' => now()->toDateString(), 'finished_at' => now()->subHour()]);
        self::assertSame($b->id, $servico->nextWorkout($plano)->id);

        GymSession::create(['workout_id' => $b->id, 'performed_on' => now()->toDateString(), 'finished_at' => now()]);
        self::assertSame($a->id, $servico->nextWorkout($plano)->id);
    }

    public function test_sessao_em_andamento_nao_avanca_a_rotacao(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $plano = $servico->createPlan('Fase 1');
        $a = $servico->addWorkout($plano, 'Treino A', null);
        $servico->addWorkout($plano, 'Treino B', null);

        GymSession::create(['workout_id' => $a->id, 'performed_on' => now()->toDateString()]);

        self::assertSame($a->id, $servico->nextWorkout($plano)->id);
    }

    public function test_editor_monta_plano_completo_pela_tela(): void
    {
        $this->entrarComoDono();

        Livewire::test(GymPlanEditor::class)
            ->set('planName', 'Fase 1')
            ->call('savePlan')
            ->assertHasNoErrors()
            ->set('workoutName', 'Treino A')
            ->set('workoutFocus', 'Peito')
            ->call('addWorkout')
            ->assertHasNoErrors();

        $treino = GymWorkout::query()->firstOrFail();

        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $treino->id)
            ->set('exName', 'Supino reto com halteres')
            ->set('exSets', '4')
            ->set('exRepsMin', '8')
            ->set('exRepsMax', '12')
            ->set('exRest', '90')
            ->set('exEquipment', 'Halteres')
            ->call('saveExercise')
            ->assertHasNoErrors()
            ->assertSet('showExerciseForm', false);

        $item = GymWorkoutExercise::query()->with('exercise', 'defaultEquipment')->firstOrFail();
        self::assertSame('Supino reto com halteres', $item->exercise->name);
        self::assertSame(4, $item->target_sets);
        self::assertSame(90, $item->rest_seconds);
        self::assertSame('Halteres', $item->defaultEquipment->name);
    }

    public function test_editar_exercicio_muda_a_meta_e_trava_nome(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $treino = $servico->addWorkout($servico->createPlan('Fase 1'), 'Treino A', null);
        $exercicio = $servico->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps);
        $item = $servico->addExercise($treino, $exercicio, ['target_sets' => 3, 'rest_seconds' => 60]);

        Livewire::test(GymPlanEditor::class)
            ->call('editExercise', $item->id)
            ->assertSet('exName', 'Supino')
            ->set('exName', 'Outro nome') // ignorado: nome fica travado na edição
            ->set('exSets', '5')
            ->set('exRest', '120')
            ->call('saveExercise')
            ->assertHasNoErrors();

        self::assertSame(5, $item->fresh()->target_sets);
        self::assertSame(120, $item->fresh()->rest_seconds);
        self::assertSame('Supino', $exercicio->fresh()->name);
        self::assertSame(1, GymExercise::query()->count());
    }

    public function test_repeticao_maxima_menor_que_a_minima_e_recusada(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $treino = $servico->addWorkout($servico->createPlan('Fase 1'), 'Treino A', null);

        Livewire::test(GymPlanEditor::class)
            ->call('newExercise', $treino->id)
            ->set('exName', 'Supino')
            ->set('exRepsMin', '12')
            ->set('exRepsMax', '8')
            ->call('saveExercise')
            ->assertHasErrors(['exRepsMax']);

        self::assertSame(0, GymWorkoutExercise::query()->count());
    }

    public function test_treino_de_outra_pessoa_nao_pode_ser_alterado_pelo_editor(): void
    {
        [$perfil, $titular, $conjuge] = $this->entrarComoDono(casal: true);
        $servico = app(GymPlanService::class);
        $treinoDoTitular = $servico->addWorkout($servico->createPlan('Fase do titular'), 'Treino A', null);

        // Agora quem opera é o cônjuge.
        $this->actingAs($conjuge->user);
        app(ProfileContext::class)->set($perfil, $conjuge);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test(GymPlanEditor::class)->call('deleteWorkout', $treinoDoTitular->id);
    }

    public function test_cliente_abre_a_academia_e_ve_o_item_no_menu(): void
    {
        [$perfil, $membro] = $this->entrarComoDono();

        $this->get(route('health.gym.index'))
            ->assertOk()
            ->assertSee('Academia')
            ->assertSee('Saúde');

        $this->get(route('health.gym.plan'))->assertOk();
    }

    public function test_sem_assinatura_nenhuma_academia_da_402(): void
    {
        $usuario = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $membro = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);
        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $membro);
        // De propósito, sem conceder assinatura nenhuma — diferente de
        // entrarComoDono(), que concede Completo.

        $this->get(route('health.gym.index'))->assertStatus(402);
    }

    public function test_consultor_com_cliente_aberto_leva_403_e_nao_ve_saude_no_menu(): void
    {
        $consultor = User::factory()->consultant()->create();
        $cliente = User::factory()->create();
        $perfil = FinancialProfile::factory()->create(['owner_user_id' => $cliente->id]);
        ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $cliente->id]);
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $cliente->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $perfil->id])
            ->get(route('health.gym.index'))
            ->assertForbidden();

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $perfil->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('health.gym.index'));
    }

    public function test_corretor_nao_acessa_a_academia_nem_ve_no_menu(): void
    {
        $corretor = User::factory()->broker()->create();

        $this->actingAs($corretor)
            ->get(route('health.gym.index'))
            ->assertRedirect(route('consultant.portfolio.insurance'));

        $this->actingAs($corretor)
            ->get(route('consultant.portfolio.insurance'))
            ->assertOk()
            ->assertDontSee(route('health.gym.index'));
    }

    public function test_home_mostra_o_proximo_treino(): void
    {
        $this->entrarComoDono();
        $servico = app(GymPlanService::class);
        $plano = $servico->createPlan('Fase 1');
        $servico->addWorkout($plano, 'Treino A', 'Peito e ombros');

        Livewire::test(GymHome::class)
            ->assertSee('Próximo treino')
            ->assertSee('Treino A')
            ->assertSee('Peito e ombros');
    }

    public function test_equipamentos_sao_por_pessoa(): void
    {
        [$perfil, $titular, $conjuge] = $this->entrarComoDono(casal: true);
        app(GymPlanService::class)->findOrCreateEquipment('Halteres');

        app(ProfileContext::class)->set($perfil, $conjuge);

        self::assertSame(0, GymEquipment::query()->count());
        // O cônjuge cria o SEU "Halteres" sem esbarrar no índice único do outro.
        self::assertNotNull(app(GymPlanService::class)->findOrCreateEquipment('Halteres'));
    }

    /**
     * Loga o titular e abre o contexto como o SetProfileContext faria.
     *
     * @return array{0: FinancialProfile, 1: ProfileMember, 2: ProfileMember|null}
     */
    private function entrarComoDono(bool $casal = false): array
    {
        $usuario = User::factory()->create();
        $perfil = $casal
            ? FinancialProfile::factory()->couple()->create(['owner_user_id' => $usuario->id])
            : FinancialProfile::factory()->create(['owner_user_id' => $usuario->id]);
        $titular = ProfileMember::factory()->create(['profile_id' => $perfil->id, 'user_id' => $usuario->id]);

        $conjuge = null;
        if ($casal) {
            $usuarioConjuge = User::factory()->create();
            $conjuge = ProfileMember::factory()->secondary()->create(['profile_id' => $perfil->id, 'user_id' => $usuarioConjuge->id]);
        }

        $this->actingAs($usuario);
        app(ProfileContext::class)->set($perfil, $titular);
        // Academia é gateada por EntitlementService (módulo Saúde) — sem
        // isso, GymHome dá 402.
        Subscription::create([
            'user_id' => $usuario->id, 'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);

        return [$perfil, $titular, $conjuge];
    }
}
