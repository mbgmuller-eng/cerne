<?php

namespace App\Livewire\Health\Gym;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymWorkout;
use App\Services\GymSessionService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Um treino só, pra olhar antes de começar: todos os exercícios, com
 * foto, meta e observações — sem os controles de editar/mover/remover
 * que a tela de montagem do plano tem. "Ver o treino" e "montar o
 * treino" são intenções diferentes; misturar as duas nessa tela deixaria
 * os dois piores.
 */
#[Layout('components.layouts.app')]
class GymWorkoutShow extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public string $workoutId;

    public function mount(string $workout): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();

        $this->workoutId = GymWorkout::query()->findOrFail($workout)->id;
    }

    public function startSession(GymSessionService $sessions): void
    {
        $sessao = $sessions->start($this->workout());

        $this->redirect(route('health.gym.session', $sessao->id), navigate: true);
    }

    public function render(GymSessionService $sessions)
    {
        $treino = $this->workout();

        return view('livewire.health.gym.gym-workout-show', [
            'treino' => $treino,
            'itens' => $treino->workoutExercises,
            'inProgress' => $sessions->inProgress(),
        ]);
    }

    private function workout(): GymWorkout
    {
        return GymWorkout::query()
            ->with('plan', 'workoutExercises.exercise', 'workoutExercises.defaultEquipment')
            ->findOrFail($this->workoutId);
    }
}
