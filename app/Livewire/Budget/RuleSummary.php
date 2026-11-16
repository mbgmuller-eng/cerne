<?php

namespace App\Livewire\Budget;

use App\Services\BudgetRuleService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * O cartão "Regra do mês" do painel e do fluxo de caixa: as três áreas, meta x lançado, com o link para a tela da regra. Sem regra, só o convite para
 * defini-la. Segue o mês que a tela de origem mostra; sem mês informado, o mês de hoje.
 */
class RuleSummary extends Component
{
    public ?int $year = null;

    public ?int $month = null;

    public function render()
    {
        $perfil = app(ProfileContext::class);
        $mostrar = $perfil->hasProfile() && ! auth()->user()?->isBroker();
        $ano = $this->year ?? CarbonImmutable::now()->year;
        $mes = $this->month ?? CarbonImmutable::now()->month;

        return view('livewire.budget.rule-summary', [
            'mostrar' => $mostrar,
            'relatorio' => $mostrar ? app(BudgetRuleService::class)->report($ano, $mes) : null,
            'ano' => $ano,
            'mes' => $mes,
        ]);
    }
}
