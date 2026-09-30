<?php

namespace Tests\Feature;

use App\Enums\ConsultantClientStatus;
use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Livewire\Health\Gym\GymSessionHistory;
use App\Models\ConsultantClient;
use App\Models\FinancialProfile;
use App\Models\GymExercise;
use App\Models\GymSession;
use App\Models\GymSetLog;
use App\Models\GymWorkout;
use App\Models\ProfileMember;
use App\Models\User;
use App\Services\GymPlanService;
use App\Support\ProfileContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Histórico de treinos: lista todo treino FINALIZADO, cada um levando ao
 * detalhe somente-leitura (GymSessionRun já sabe se mostrar assim sozinho
 * — ver mount()/render() de lá — só faltava o link até aqui).
 */
class GymSessionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;
    private FinancialProfile $perfil;
    private ProfileMember $membro;
    private GymWorkout $treino;
    private GymExercise $supino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();
        $this->perfil = FinancialProfile::factory()->create(['owner_user_id' => $this->usuario->id]);
        $this->membro = ProfileMember::factory()->create(['profile_id' => $this->perfil->id, 'user_id' => $this->usuario->id]);
        $this->actingAs($this->usuario);
        app(ProfileContext::class)->set($this->perfil, $this->membro);

        $plano = app(GymPlanService::class);
        $this->treino = $plano->addWorkout($plano->createPlan('Fase 1'), 'Treino A', null);
        $this->supino = $plano->findOrCreateExercise('Supino', GymMuscleGroup::Chest, GymMeasureType::LoadReps);
    }

    public function test_lista_so_treino_finalizado_mais_recente_primeiro(): void
    {
        $antigo = $this->criarSessao(finalizada: true, diasAtras: 10);
        $emAndamento = $this->criarSessao(finalizada: false, diasAtras: 1);
        $recente = $this->criarSessao(finalizada: true, diasAtras: 2);

        $componente = Livewire::test(GymSessionHistory::class);

        $componente->assertSeeInOrder([$recente->performed_on->format('d/m/Y'), $antigo->performed_on->format('d/m/Y')]);
        self::assertFalse(str_contains($componente->html(), route('health.gym.session', $emAndamento->id)));
    }

    public function test_cada_linha_linka_pro_detalhe_da_sessao(): void
    {
        $sessao = $this->criarSessao(finalizada: true);

        Livewire::test(GymSessionHistory::class)
            ->assertSee(route('health.gym.session', $sessao->id), false);
    }

    public function test_sem_treino_finalizado_mostra_vazio(): void
    {
        Livewire::test(GymSessionHistory::class)
            ->assertSee('Nenhum treino finalizado ainda.');
    }

    public function test_treino_finalizado_visto_direto_pela_rota_e_somente_leitura(): void
    {
        $sessao = $this->criarSessao(finalizada: true);

        $this->get(route('health.gym.session', $sessao->id))
            ->assertOk()
            ->assertSee($this->treino->name)
            ->assertDontSee('Finalizar treino');
    }

    public function test_consultor_leva_403(): void
    {
        $consultor = User::factory()->consultant()->create();
        ConsultantClient::factory()->create([
            'consultant_id' => $consultor->id, 'client_id' => $this->usuario->id, 'status' => ConsultantClientStatus::Active,
        ]);

        $this->actingAs($consultor)
            ->withSession(['cerne.active_profile_id' => $this->perfil->id])
            ->get(route('health.gym.history'))
            ->assertForbidden();
    }

    private function criarSessao(bool $finalizada, int $diasAtras = 1): GymSession
    {
        $data = now()->subDays($diasAtras);

        $sessao = GymSession::create([
            'workout_id' => $this->treino->id,
            'performed_on' => $data->toDateString(),
            'started_at' => $data,
            'finished_at' => $finalizada ? $data->copy()->addHour() : null,
        ]);

        GymSetLog::create([
            'session_id' => $sessao->id, 'exercise_id' => $this->supino->id, 'set_number' => 1,
            'reps' => 10, 'load_value' => '20', 'load_mode' => \App\Enums\GymLoadMode::Total,
            'completed_at' => $data->copy()->addMinutes(5),
        ]);

        return $sessao;
    }
}
