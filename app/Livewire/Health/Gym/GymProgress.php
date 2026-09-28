<?php

namespace App\Livewire\Health\Gym;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Services\GymProgressService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Visão geral da evolução: cada exercício com histórico, o melhor
 * resultado e o aviso de "subir carga" ou "platô".
 */
#[Layout('components.layouts.app')]
class GymProgress extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
    }

    public function render(GymProgressService $progress)
    {
        return view('livewire.health.gym.gym-progress', [
            'rows' => $progress->overview(),
            'progress' => $progress,
        ]);
    }
}
