<?php

namespace App\Livewire\Consultant;

use App\Services\ConsultantPortfolioService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Painel da carteira do consultor: panorama agregado de todos os clientes
 * com vínculo ativo — patrimônio total, cobertura de seguros etc. Não é o
 * detalhe de um perfil (isso é App\Livewire\Dashboard) nem a gestão de
 * vínculos/convites (isso é ClientDashboard).
 */
#[Layout('components.layouts.app')]
class PortfolioOverview extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function render(ConsultantPortfolioService $portfolio)
    {
        return view('livewire.consultant.portfolio-overview', [
            'dados' => $portfolio->overview(auth()->user()),
        ]);
    }
}
