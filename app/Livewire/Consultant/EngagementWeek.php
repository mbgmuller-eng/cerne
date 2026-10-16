<?php

namespace App\Livewire\Consultant;

use App\Services\EngagementWeekService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Minha semana: o que pede atenção do consultor nos acompanhamentos de todos os clientes. Só consultor financeiro.
 */
#[Layout('components.layouts.app')]
class EngagementWeek extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function render(EngagementWeekService $semana)
    {
        return view('livewire.consultant.engagement-week', ['dados' => $semana->forConsultant(auth()->user())]);
    }
}
