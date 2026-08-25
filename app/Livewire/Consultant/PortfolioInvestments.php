<?php

namespace App\Livewire\Consultant;

use App\Services\ConsultantPortfolioService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Investimentos de TODOS os clientes ativos do consultor, numa lista só
 * — um ativo por linha, filtrável por instituição/corretora. Mesma
 * lógica de PortfolioInsurance, só que para investimentos.
 */
#[Layout('components.layouts.app')]
class PortfolioInvestments extends Component
{
    #[Url]
    public string $instituicao = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function render(ConsultantPortfolioService $portfolio)
    {
        $todos = $portfolio->allActiveInvestments(auth()->user());

        $instituicoes = $todos
            ->pluck('investment.institution')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $linhas = $this->instituicao === ''
            ? $todos
            : $todos->filter(fn (array $linha): bool => $linha['investment']->institution === $this->instituicao);

        return view('livewire.consultant.portfolio-investments', [
            'linhas' => $linhas->values(),
            'instituicoes' => $instituicoes,
            'totalGeral' => $todos->count(),
        ]);
    }
}
