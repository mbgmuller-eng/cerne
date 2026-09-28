<?php

namespace App\Livewire\Health\Gym;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymSession;
use App\Models\GymWorkout;
use App\Services\GymPlanService;
use App\Services\GymProgressService;
use App\Services\GymSessionService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Cerne Saúde › Academia: a fase ativa, o treino da vez na rotação e as
 * últimas sessões. O registro da sessão fica em GymSessionRun.
 */
#[Layout('components.layouts.app')]
class GymHome extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
    }

    /** Abre a sessão do treino (ou retoma a que está em andamento) e vai pra tela de registro. */
    public function startSession(string $workoutId, GymSessionService $sessions): void
    {
        $sessao = $sessions->start(GymWorkout::query()->findOrFail($workoutId));

        $this->redirect(route('health.gym.session', $sessao->id), navigate: true);
    }

    public function render(GymPlanService $service, GymSessionService $sessions, GymProgressService $progress)
    {
        $plan = $service->activePlan();
        $workouts = $plan?->workouts()->withCount('workoutExercises')->get() ?? collect();

        return view('livewire.health.gym.gym-home', [
            'plan' => $plan,
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
