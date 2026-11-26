<?php

namespace App\Livewire\Activities;

use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Services\Activities\ActivityProgressService;
use App\Support\Charts\ChartPalette;
use App\Support\Charts\SvgCharts;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** A evolução: carga por semana e por tipo, vezes nas últimas quatro semanas e dor. */
#[Layout('components.layouts.app')]
class ActivityProgress extends Component
{
    use ActivityScreen;

    public function mount(): void
    {
        $this->guardActivityScreen();
    }

    public function render(ActivityProgressService $progress)
    {
        $dados = $progress->weekly();
        $paleta = ChartPalette::screen();
        $series = [];
        $legenda = [];

        foreach (array_keys(ActivityProgressService::GROUPS) as $i => $grupo) {
            $nome = ActivityProgressService::GROUPS[$grupo];
            $series[] = [$nome, array_map(fn (array $s) => $s['load'][$grupo], $dados['weeks']), $paleta->serie($i)];
            $legenda[] = [$nome, $paleta->serie($i)];
        }

        return view('livewire.activities.activity-progress', [
            'dados' => $dados,
            'grupos' => ActivityProgressService::GROUPS,
            'legenda' => $legenda,
            'grafico' => SvgCharts::colunasEmpilhadas(
                array_map(fn (array $s) => $s['start']->format('d/m'), $dados['weeks']),
                $series, $paleta, 640, 260, 'Carga por semana e por tipo de atividade',
                fn (float $v) => number_format($v, 0, ',', '.'),
            ),
        ]);
    }
}
