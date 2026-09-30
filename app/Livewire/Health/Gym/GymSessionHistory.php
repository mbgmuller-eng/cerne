<?php

namespace App\Livewire\Health\Gym;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\GymSession;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Todo treino finalizado, do mais recente pro mais antigo — cada linha
 * abre o detalhe (GymSessionRun em modo somente-leitura, ver rota
 * health.gym.session). "Últimas sessões" na Home só mostra 5; aqui é
 * tudo.
 */
#[Layout('components.layouts.app')]
class GymSessionHistory extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
    }

    public function render()
    {
        return view('livewire.health.gym.gym-session-history', [
            'sessoes' => GymSession::query()
                ->whereNotNull('finished_at')
                ->with('workout')
                ->withCount('setLogs')
                ->latest('performed_on')
                ->get(),
        ]);
    }
}
