<?php

namespace App\Livewire\Health\Gym;

use App\Enums\GymGoal;
use App\Enums\GymLevel;
use App\Enums\PlatformModule;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresModule;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymSession;
use App\Models\GymWorkout;
use App\Services\GymPhaseReviewService;
use App\Services\GymPlanService;
use App\Services\GymProgressService;
use App\Services\GymSessionService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Cerne Saúde › Academia: a fase ativa, o treino da vez na rotação e as
 * últimas sessões. O registro da sessão fica em GymSessionRun.
 */
#[Layout('components.layouts.app')]
class GymHome extends Component
{
    use RequiresActiveProfile, RequiresModule, RequiresPersonalHealth;

    public bool $showGoal = false;

    public string $goal = '';

    public string $level = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
        $this->abortUnlessModuleEntitled(PlatformModule::Saude);
    }

    /** Abre a sessão do treino (ou retoma a que está em andamento) e vai pra tela de registro. */
    public function startSession(string $workoutId, GymSessionService $sessions): void
    {
        $sessao = $sessions->start(GymWorkout::query()->findOrFail($workoutId));

        $this->redirect(route('health.gym.session', $sessao->id), navigate: true);
    }

    /** Fase montada à mão não guarda objetivo: aqui a pessoa o informa para o aviso de troca saber o prazo certo. */
    public function openGoal(GymPlanService $service): void
    {
        $plano = $service->activePlan();
        abort_if($plano === null, 404);

        $this->goal = $plano->goal?->value ?? '';
        $this->level = $plano->level?->value ?? '';
        $this->resetValidation();
        $this->showGoal = true;
    }

    public function saveGoal(GymPlanService $service): void
    {
        $this->validate([
            'goal' => ['required', Rule::in(GymGoal::values())],
            'level' => ['required', Rule::in(GymLevel::values())],
        ], attributes: ['goal' => 'objetivo', 'level' => 'nível']);

        $plano = $service->activePlan();
        abort_if($plano === null, 404);

        $service->setPlanGoal($plano, GymGoal::from($this->goal), GymLevel::from($this->level));
        $this->showGoal = false;
    }

    public function render(GymPlanService $service, GymSessionService $sessions, GymProgressService $progress, GymPhaseReviewService $reviews)
    {
        $plan = $service->activePlan();
        $workouts = $plan?->workouts()->withCount('workoutExercises')->get() ?? collect();

        return view('livewire.health.gym.gym-home', [
            'plan' => $plan,
            'phase' => $plan ? $reviews->review($plan) : null,
            'objetivos' => GymGoal::cases(),
            'niveis' => GymLevel::options(),
            'workouts' => $workouts,
            'inProgress' => $sessions->inProgress()?->load('workout'),
            // Só os que pedem ação: subir a carga ou sair do platô.
            'attention' => $progress->overview()->whereIn('status', ['raise', 'plateau'])->take(4),
            'next' => $plan ? $service->nextWorkout($plan) : null,
            'recent' => GymSession::query()
                ->whereNotNull('finished_at')
                ->with('workout')
                ->latest('finished_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
