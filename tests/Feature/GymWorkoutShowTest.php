<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Enums\SubscriptionBundle;
use App\Enums\SubscriptionKind;
use App\Enums\SubscriptionStatus;
use App\Livewire\Health\Gym\GymWorkoutShow;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\GymWorkout;
use App\Models\ProfileMember;
use App\Models\Subscription;
use App\Models\User;
use App\Services\GymPlanService;
use App\Services\GymSessionService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Ver treino": consulta só-leitura de um treino do plano, pra olhar os
 * exercícios (com foto) antes de começar — sem os controles de editar
 * que a tela de montagem do plano tem.
 */
class GymWorkoutShowTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;
    private FinancialProfile $perfil;
    private ProfileMember $membro;
    private GymWorkout $treino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->couple()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);
        // Academia é gateada por EntitlementService (módulo Saúde) — sem
        // isso, GymHome dá 402.
        Subscription::create([
            'user_id' => $this->usuario->id, 'kind' => SubscriptionKind::Direct,
            'bundle' => SubscriptionBundle::Completo, 'status' => SubscriptionStatus::Active, 'started_at' => now(),
        ]);

        $service = app(GymPlanService::class);
        $this->treino = $service->addWorkout($service->createPlan('Fase 1'), 'Treino A', 'Peito e ombros');
        $exercicio = $service->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps);
        $service->addExercise($this->treino, $exercicio, [
            'target_sets' => 3, 'target_reps_min' => 8, 'target_reps_max' => 12, 'rest_seconds' => 90, 'notes' => 'Cuidado com o ombro.',
        ]);
    }

    public function test_mostra_o_treino_com_o_exercicio_e_a_meta(): void
    {
        $this->get(route('health.gym.workout', $this->treino))
            ->assertOk()
            ->assertSee('Treino A')
            ->assertSee('Peito e ombros')
            ->assertSee('Supino')
            ->assertSee('8–12')
            ->assertSee('pausa 90s')
            ->assertSee('Cuidado com o ombro.')
            ->assertSee('Iniciar treino');
    }

    public function test_treino_sem_exercicio_mostra_convite_pra_adicionar(): void
    {
        $service = app(GymPlanService::class);
        $vazio = $service->addWorkout($service->activePlan(), 'Treino B', null);

        $this->get(route('health.gym.workout', $vazio))
            ->assertOk()
            ->assertSee('ainda não tem exercícios');
    }

    public function test_iniciar_treino_cria_a_sessao_e_redireciona(): void
    {
        Livewire::test(GymWorkoutShow::class, ['workout' => $this->treino->id])
            ->call('startSession')
            ->assertRedirect(route('health.gym.session', \App\Models\GymSession::query()->firstOrFail()->id));
    }

    public function test_com_sessao_em_andamento_no_mesmo_treino_mostra_retomar(): void
    {
        app(GymSessionService::class)->start($this->treino);

        $this->get(route('health.gym.workout', $this->treino))
            ->assertOk()
            ->assertSee('Retomar treino')
            ->assertDontSee('Iniciar treino');
    }

    public function test_com_sessao_em_andamento_em_outro_treino_avisa_em_vez_de_deixar_iniciar(): void
    {
        $service = app(GymPlanService::class);
        $outro = $service->addWorkout($service->activePlan(), 'Treino B', null);
        app(GymSessionService::class)->start($outro);

        $this->get(route('health.gym.workout', $this->treino))
            ->assertOk()
            ->assertSee('em andamento em outro lugar')
            ->assertDontSee('Iniciar treino');
    }

    public function test_treino_de_outra_pessoa_da_404(): void
    {
        $conjuge = ProfileMember::factory()->secondary()->create([
            'profile_id' => $this->perfil->id, 'user_id' => User::factory()->create()->id,
        ]);
        $this->actingAs($conjuge->user)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.gym.workout', $this->treino))
            ->assertNotFound();
    }

    public function test_consultor_leva_403(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuario->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.gym.workout', $this->treino))
            ->assertForbidden();
    }

    public function test_home_linka_pro_treino_e_pra_cada_card(): void
    {
        $this->get(route('health.gym.index'))
            ->assertOk()
            ->assertSee('Ver treino')
            ->assertSee(route('health.gym.workout', $this->treino), false);
    }
}
