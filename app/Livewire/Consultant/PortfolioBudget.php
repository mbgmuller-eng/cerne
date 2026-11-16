<?php

namespace App\Livewire\Consultant;

use App\Services\BudgetPortfolioService;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Regra de orçamento da carteira: para cada cliente ativo, o mês com meta x lançado por área, e quantos estão dentro dos limites e batendo a meta de
 * investimento. Só leitura; o consultor edita a regra de um cliente abrindo o perfil dele (ou aplicando um modelo em Modelos de regra).
 */
#[Layout('components.layouts.app')]
class PortfolioBudget extends Component
{
    #[Url(as: 'mes')]
    public string $ym = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->ym) !== 1) {
            $this->ym = CarbonImmutable::now()->format('Y-m');
        }
    }

    public function previousMonth(): void
    {
        $this->ym = CarbonImmutable::createFromFormat('Y-m-d', $this->ym.'-01')->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->ym = CarbonImmutable::createFromFormat('Y-m-d', $this->ym.'-01')->addMonthNoOverflow()->format('Y-m');
    }

    public function currentMonth(): void
    {
        $this->ym = CarbonImmutable::now()->format('Y-m');
    }

    public function render()
    {
        $ano = (int) substr($this->ym, 0, 4);
        $mes = (int) substr($this->ym, 5, 2);

        return view('livewire.consultant.portfolio-budget', [
            'ano' => $ano,
            'mes' => $mes,
            'visao' => app(BudgetPortfolioService::class)->overview(auth()->user(), $ano, $mes),
            'ehMesAtual' => $this->ym === CarbonImmutable::now()->format('Y-m'),
        ]);
    }
}
