<?php

namespace App\Livewire\Consultant;

use App\Services\ConsultantPortfolioService;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Dívidas de TODOS os clientes ativos do consultor, por cliente: quanto devem, quanto pagam de parcela no mês e cada contrato.
 * Só leitura: quem registra e paga é o cliente (ver ConsultantPortfolioService::debtOverview).
 */
#[Layout('components.layouts.app')]
class PortfolioDebts extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function render(ConsultantPortfolioService $portfolio)
    {
        $visao = $portfolio->debtOverview(auth()->user());

        return view('livewire.consultant.portfolio-debts', [
            'clientes' => $visao['clients'],
            'totais' => $visao['totals'],
        ]);
    }
}
