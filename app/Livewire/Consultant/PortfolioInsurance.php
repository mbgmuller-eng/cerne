<?php

namespace App\Livewire\Consultant;

use App\Services\ConsultantPortfolioService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Seguros de TODOS os clientes ativos do consultor, numa lista só —
 * uma linha por apólice, filtrável por seguradora. Diferente do
 * Painel da carteira (que resume por cliente), aqui o corte é "quem
 * mais tem apólice com a seguradora X" — útil pra falar com a
 * seguradora sobre a carteira inteira, não cliente a cliente.
 */
#[Layout('components.layouts.app')]
class PortfolioInsurance extends Component
{
    #[Url]
    public string $seguradora = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function render(ConsultantPortfolioService $portfolio)
    {
        $todas = $portfolio->allActivePolicies(auth()->user());

        $seguradoras = $todas
            ->pluck('policy.insurer_name')
            ->unique()
            ->sort()
            ->values();

        $linhas = $this->seguradora === ''
            ? $todas
            : $todas->filter(fn (array $linha): bool => $linha['policy']->insurer_name === $this->seguradora);

        return view('livewire.consultant.portfolio-insurance', [
            'linhas' => $linhas->values(),
            'seguradoras' => $seguradoras,
            'totalGeral' => $todas->count(),
        ]);
    }
}
