<?php

namespace App\Livewire\Consultant;

use App\Enums\LeadKind;
use App\Services\LeadMetricsService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Métricas do funil de leads do profissional: quantos entraram, fecharam ou se perderam, de onde vêm os que fecham,
 * por que se perdem e quanto de prêmio e comissão saiu deles. Só dados do próprio profissional (ver LeadMetricsService).
 */
#[Layout('components.layouts.app')]
class LeadMetrics extends Component
{
    private const PERIODOS = [
        '30' => 'Últimos 30 dias',
        '90' => 'Últimos 90 dias',
        '180' => 'Últimos 6 meses',
        '365' => 'Último ano',
    ];

    #[Url]
    public string $aba = '';

    #[Url]
    public string $periodo = '90';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);

        // Mesma regra de LeadsIndex: corretor só tem o funil de seguros.
        if (auth()->user()->isBroker() || ! in_array($this->aba, ['consultoria', 'seguros'], true)) {
            $this->aba = auth()->user()->isBroker() ? 'seguros' : 'consultoria';
        }

        if (! array_key_exists($this->periodo, self::PERIODOS)) {
            $this->periodo = '90';
        }
    }

    public function setAba(string $aba): void
    {
        if (! auth()->user()->isBroker() && in_array($aba, ['consultoria', 'seguros'], true)) {
            $this->aba = $aba;
        }
    }

    public function render()
    {
        $kind = $this->aba === 'seguros' ? LeadKind::Insurance : LeadKind::Consulting;
        $ate = CarbonImmutable::now();
        $de = $ate->subDays((int) $this->periodo)->startOfDay();

        return view('livewire.consultant.lead-metrics', [
            'kind' => $kind,
            'periodos' => self::PERIODOS,
            'de' => $de,
            'ate' => $ate,
            'metricas' => app(LeadMetricsService::class)->funnel(auth()->user(), $kind, $de, $ate),
            'ehCorretor' => auth()->user()->isBroker(),
        ]);
    }
}
