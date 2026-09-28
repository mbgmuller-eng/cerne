<?php

namespace App\Livewire\Health\Gym;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymExercise;
use App\Models\GymWorkoutExercise;
use App\Services\GymProgressService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Histórico de UM exercício: gráfico, melhor marca, sugestão e o
 * detalhe de cada sessão.
 */
#[Layout('components.layouts.app')]
class GymExerciseHistory extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public string $exerciseId = '';

    public function mount(string $exercise): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();

        $this->exerciseId = GymExercise::query()->findOrFail($exercise)->id;
    }

    public function render(GymProgressService $progress)
    {
        $exercicio = GymExercise::query()->findOrFail($this->exerciseId);

        // A meta (faixa de repetições) vem do plano ativo, se o exercício estiver nele.
        $meta = GymWorkoutExercise::query()
            ->where('exercise_id', $exercicio->id)
            ->whereHas('workout.plan', fn ($q) => $q->where('is_active', true))
            ->first();

        $analise = $progress->analyze($exercicio, $meta);

        return view('livewire.health.gym.gym-exercise-history', [
            'exercicio' => $exercicio,
            'analise' => $analise,
            'grafico' => $progress->chart($analise['sessions']),
            'progress' => $progress,
            'meta' => $meta,
            // Mais recente primeiro na lista; o gráfico segue a ordem cronológica.
            'sessoes' => array_reverse($analise['sessions']),
        ]);
    }
}
