<?php

namespace App\Livewire\Tutorial;

use App\Models\TutorialProgress;
use Livewire\Component;

/** Em Minha conta: faz os cartões de primeira visita voltarem a aparecer em todas as telas. */
class ResetTutorials extends Component
{
    public bool $done = false;

    public function showAgain(): void
    {
        $this->done = false;

        // Quem está vendo como outra pessoa não mexe na conta dela.
        if (! auth()->check() || session()->has('impersonator_id')) {
            return;
        }

        TutorialProgress::query()->where('user_id', auth()->id())->delete();
        $this->done = true;
    }

    public function render()
    {
        return view('livewire.tutorial.reset-tutorials');
    }
}
