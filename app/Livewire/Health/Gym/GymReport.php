<?php

namespace App\Livewire\Health\Gym;

use App\Enums\PlatformModule;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresModule;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Services\GymProgressService;
use App\Services\GymReportService;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Relatório de evolução da Academia na tela e em PDF: resumo, frequência, destaques e, para cada treino, cada exercício com de
 * onde saiu e onde chegou. O período vem de atalhos (30 dias, 3 meses, 6 meses, 1 ano, "desta fase") ou de datas livres.
 */
#[Layout('components.layouts.app')]
class GymReport extends Component
{
    use RequiresActiveProfile, RequiresModule, RequiresPersonalHealth;

    private const PRESETS = ['30' => 'Últimos 30 dias', '90' => 'Últimos 3 meses', '180' => 'Últimos 6 meses', '365' => 'Último ano', 'fase' => 'Desta fase', 'custom' => 'Escolher datas'];

    #[Url]
    public string $periodo = '90';

    #[Url]
    public string $inicio = '';

    #[Url]
    public string $fim = '';

    public function mount(GymReportService $reports): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
        $this->abortUnlessModuleEntitled(PlatformModule::Saude);

        if (! array_key_exists($this->periodo, self::PRESETS)) {
            $this->periodo = '90';
        }

        // "Desta fase" sem fase ativa cai nos 3 meses: não existe início para usar.
        if ($this->periodo === 'fase' && $reports->activePlan() === null) {
            $this->periodo = '90';
        }

        if ($this->periodo === 'custom' && ($this->inicio === '' || $this->fim === '')) {
            $this->periodo = '90';
        }
    }

    public function updatedPeriodo(): void
    {
        $this->resetErrorBag();

        if ($this->periodo === 'custom' && $this->inicio === '') {
            $this->inicio = now()->subMonths(3)->toDateString();
            $this->fim = now()->toDateString();
        }
    }

    public function baixarPdf(GymReportService $reports, GymProgressService $progress)
    {
        [$inicio, $fim] = $this->intervalo($reports);

        // Datas inválidas: o aviso já está na tela, e o PDF não sai com um período que a pessoa não escolheu.
        if ($this->getErrorBag()->isNotEmpty()) {
            return null;
        }

        $pdf = Pdf::loadView('pdf.gym-report', [
            'relatorio' => $reports->report($inicio, $fim),
            'progress' => $progress,
            'membro' => app(ProfileContext::class)->member(),
            'geradoEm' => now(),
        ])->setPaper('a4');

        return response()->streamDownload(fn () => print ($pdf->output()), 'relatorio-academia-'.$inicio->format('Y-m-d').'-a-'.$fim->format('Y-m-d').'.pdf');
    }

    public function render(GymReportService $reports, GymProgressService $progress)
    {
        [$inicio, $fim] = $this->intervalo($reports);

        return view('livewire.health.gym.gym-report', [
            'relatorio' => $reports->report($inicio, $fim),
            'progress' => $progress,
            'atalhos' => self::PRESETS,
            'temFase' => $reports->activePlan() !== null,
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function intervalo(GymReportService $reports): array
    {
        $hoje = CarbonImmutable::today();

        $this->resetErrorBag();

        if ($this->periodo === 'custom') {
            $validador = Validator::make(['inicio' => $this->inicio, 'fim' => $this->fim], [
                'inicio' => ['required', 'date', 'before_or_equal:today'],
                'fim' => ['required', 'date', 'after_or_equal:inicio', 'before_or_equal:today'],
            ], [], ['inicio' => 'data inicial', 'fim' => 'data final']);

            if ($validador->fails()) {
                foreach ($validador->errors()->messages() as $campo => $mensagens) {
                    $this->addError($campo, $mensagens[0]);
                }

                // A tela segue mostrando os últimos 3 meses até as datas ficarem válidas.
                return [$hoje->subDays(89)->startOfDay(), $hoje->endOfDay()];
            }

            return [CarbonImmutable::parse($this->inicio)->startOfDay(), CarbonImmutable::parse($this->fim)->endOfDay()];
        }

        if ($this->periodo === 'fase') {
            $inicioDaFase = $reports->activePlan()?->started_on;

            return [CarbonImmutable::parse($inicioDaFase ?? $hoje->subDays(90))->startOfDay(), $hoje->endOfDay()];
        }

        return [$hoje->subDays((int) $this->periodo - 1)->startOfDay(), $hoje->endOfDay()];
    }
}
