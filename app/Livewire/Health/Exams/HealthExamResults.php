<?php

namespace App\Livewire\Health\Exams;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Livewire\Health\Exams\Concerns\FindsExamPeople;
use App\Services\HealthExamResultService;
use App\Support\Charts\HealthCharts;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Comparar resultados: os valores por data, com o gráfico de cada um, e as conclusões dos laudos lado a lado. */
#[Layout('components.layouts.app')]
class HealthExamResults extends Component
{
    use FindsExamPeople, RequiresActiveProfile, RequiresPersonalHealth;

    #[Url(as: 'pessoa')]
    public string $memberId = '';

    #[Url(as: 'ver')]
    public string $view = 'valores';

    public string $marker = '';

    public string $exam = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();
    }

    public function render(HealthExamResultService $service)
    {
        $pessoas = $this->people();

        // O id vem da URL: só vale quem a pessoa que olha enxerga.
        if ($pessoas->firstWhere('id', $this->memberId) === null) {
            $this->memberId = $this->defaultPersonId();
        }

        $comparacao = $service->comparison($this->memberId);
        $linhas = collect($comparacao['groups'])->flatten(1)->keyBy('key');
        // Abre num marcador que tenha o que comparar; sem isso, no primeiro.
        $escolhida = $linhas->get($this->marker) ?? $linhas->first(fn (array $l) => count($l['cells']) > 1) ?? $linhas->first();
        $evolucao = $escolhida !== null ? $service->trend($escolhida) : null;
        $laudos = $service->textHistory($this->memberId);
        $exame = isset($laudos[$this->exam]) ? $this->exam : (string) array_key_first($laudos);

        return view('livewire.health.exams.health-exam-results', [
            'pessoas' => $pessoas,
            'comparacao' => $comparacao,
            'escolhida' => $escolhida,
            'evolucao' => $evolucao,
            'grafico' => $evolucao !== null ? HealthCharts::trend($evolucao['points'], $evolucao['min'], $evolucao['max'], 'Evolução de '.$escolhida['name']) : '',
            'laudos' => $laudos,
            'exameEscolhido' => $exame,
        ]);
    }
}
