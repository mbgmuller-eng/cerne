<?php

namespace App\Livewire\Investments;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Services\Analytics\InvestmentAnalytics;
use App\Support\Charts\ChartPalette;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Análises de investimentos: a concentração do patrimônio por instituição (o desempenho no tempo já mora em Desempenho). */
#[Layout('components.layouts.app')]
class InvestmentAnalysis extends Component
{
    use RequiresActiveProfile;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        abort_if(auth()->user()?->isBroker(), 403);
    }

    public function baixarPdf()
    {
        $pdf = Pdf::loadView('pdf.analytics', [
            'relatorio' => app(InvestmentAnalytics::class)->report(ChartPalette::pdf()),
            'perfil' => app(ProfileContext::class)->profile()?->profile_name,
            'geradoEm' => now(),
        ])->setPaper('a4');

        return response()->streamDownload(fn () => print ($pdf->output()), 'analises-investimentos-'.now()->format('Y-m-d').'.pdf');
    }

    public function render()
    {
        return view('livewire.investments.investment-analysis', [
            'relatorio' => app(InvestmentAnalytics::class)->report(ChartPalette::screen()),
        ]);
    }
}
