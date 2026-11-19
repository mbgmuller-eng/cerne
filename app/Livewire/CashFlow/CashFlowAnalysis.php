<?php

namespace App\Livewire\CashFlow;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Services\Analytics\CashFlowAnalytics;
use App\Support\Charts\ChartPalette;
use App\Support\ProfileContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Análises do fluxo de caixa: rosca por categoria (com as subcategorias ao clicar), colunas por mês, ritmo de gasto e destino de cada R$ 100 de
 * receita. A tela e o PDF leem o MESMO relatório (CashFlowAnalytics::report); só a paleta muda (variáveis CSS na tela, hexadecimais no PDF).
 */
#[Layout('components.layouts.app')]
class CashFlowAnalysis extends Component
{
    use RequiresActiveProfile;

    #[Url]
    public ?int $ano = null;

    #[Url]
    public ?int $mes = null;

    /** A categoria aberta na rosca. O id vem do navegador e é conferido sob o escopo a cada renderização. */
    public string $categoriaId = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        // Corretor só entra em Seguros: nenhuma outra tela financeira.
        abort_if(auth()->user()?->isBroker(), 403);
    }

    public function previousMonth(): void
    {
        $this->irPara($this->referencia()->subMonth());
    }

    public function nextMonth(): void
    {
        $this->irPara($this->referencia()->addMonth());
    }

    public function currentMonth(): void
    {
        $this->ano = null;
        $this->mes = null;
        $this->categoriaId = '';
    }

    public function abrirCategoria(string $id): void
    {
        $this->categoriaId = $this->categoriaId === $id ? '' : $id;
    }

    public function baixarPdf()
    {
        $ref = $this->referencia();
        $pdf = Pdf::loadView('pdf.analytics', [
            'relatorio' => app(CashFlowAnalytics::class)->report($ref->year, $ref->month, ChartPalette::pdf()),
            'perfil' => app(ProfileContext::class)->profile()?->profile_name,
            'geradoEm' => now(),
        ])->setPaper('a4');

        return response()->streamDownload(fn () => print ($pdf->output()), 'analises-fluxo-de-caixa-'.$ref->format('Y-m').'.pdf');
    }

    private function irPara(CarbonImmutable $alvo): void
    {
        $this->ano = $alvo->year;
        $this->mes = $alvo->month;
        $this->categoriaId = '';
    }

    private function referencia(): CarbonImmutable
    {
        $hoje = CarbonImmutable::now();

        return CarbonImmutable::create($this->ano ?? $hoje->year, min(12, max(1, $this->mes ?? $hoje->month)), 1);
    }

    public function render()
    {
        $ref = $this->referencia();
        $servico = app(CashFlowAnalytics::class);

        return view('livewire.cash-flow.cash-flow-analysis', [
            'relatorio' => $servico->report($ref->year, $ref->month, ChartPalette::screen()),
            'referencia' => $ref,
            'ehMesAtual' => $ref->isSameMonth(CarbonImmutable::now()),
            'abertas' => $this->categoriaId !== '' ? $servico->subcategorias($ref->year, $ref->month, $this->categoriaId) : null,
        ]);
    }
}
