<?php

namespace App\Livewire\Debts;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Services\Analytics\DebtAnalytics;
use App\Support\Charts\ChartPalette;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Análises de dívidas: quanto de cada parcela é juro. Uma dívida por vez (a que tem cronograma com juros); a escolha vem do navegador e é
 * resolvida DENTRO das dívidas que a pessoa enxerga, nunca por id solto.
 */
#[Layout('components.layouts.app')]
class DebtAnalysis extends Component
{
    use RequiresActiveProfile;

    #[Url]
    public string $divida = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        abort_if(auth()->user()?->isBroker(), 403);
    }

    public function baixarPdf()
    {
        $escolhida = $this->escolhida();
        abort_if($escolhida === null, 404);

        $pdf = Pdf::loadView('pdf.analytics', [
            'relatorio' => app(DebtAnalytics::class)->report($escolhida, ChartPalette::pdf()),
            'perfil' => app(ProfileContext::class)->profile()?->profile_name,
            'geradoEm' => now(),
        ])->setPaper('a4');

        return response()->streamDownload(fn () => print ($pdf->output()), 'analises-dividas-'.str($escolhida->name)->slug().'.pdf');
    }

    private function escolhida()
    {
        $servico = app(DebtAnalytics::class);
        $lista = $servico->selecionaveis();

        return $lista->firstWhere('id', $this->divida) ?? $servico->padrao($lista);
    }

    public function render()
    {
        $servico = app(DebtAnalytics::class);
        $lista = $servico->selecionaveis();
        $escolhida = $lista->firstWhere('id', $this->divida) ?? $servico->padrao($lista);

        return view('livewire.debts.debt-analysis', [
            'dividas' => $lista,
            'escolhida' => $escolhida,
            'relatorio' => $escolhida ? $servico->report($escolhida, ChartPalette::screen()) : null,
        ]);
    }
}
