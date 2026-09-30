<?php

namespace App\Livewire\Health\Gym;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Services\GymProgressService;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Visão geral da evolução: cada exercício com histórico, o melhor
 * resultado e o aviso de "subir carga" ou "platô". Também exporta um
 * relatório em PDF da evolução dentro de um período escolhido.
 */
#[Layout('components.layouts.app')]
class GymProgress extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public string $inicioRelatorio = '';
    public string $fimRelatorio = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();

        // Últimos 3 meses por padrão — intervalo curto o bastante pra caber
        // numa página, mas com treino suficiente pra mostrar evolução.
        $this->inicioRelatorio = now()->subMonths(3)->startOfDay()->toDateString();
        $this->fimRelatorio = now()->toDateString();
    }

    public function rules(): array
    {
        return [
            'inicioRelatorio' => ['required', 'date'],
            'fimRelatorio' => ['required', 'date', 'after_or_equal:inicioRelatorio'],
        ];
    }

    public function baixarRelatorio(GymProgressService $progress)
    {
        $this->validate();

        $inicio = Carbon::parse($this->inicioRelatorio)->startOfDay();
        $fim = Carbon::parse($this->fimRelatorio)->endOfDay();

        $pdf = Pdf::loadView('pdf.gym-evolution-report', [
            'linhas' => $progress->periodReport($inicio, $fim),
            'progress' => $progress,
            'inicio' => $inicio,
            'fim' => $fim,
            'membro' => app(ProfileContext::class)->member(),
            'geradoEm' => now(),
        ])->setPaper('a4');

        $nomeArquivo = 'evolucao-academia-'.$inicio->format('Y-m-d').'-a-'.$fim->format('Y-m-d').'.pdf';

        return response()->streamDownload(fn () => print ($pdf->output()), $nomeArquivo);
    }

    public function render(GymProgressService $progress)
    {
        return view('livewire.health.gym.gym-progress', [
            'rows' => $progress->overview(),
            'progress' => $progress,
        ]);
    }
}
