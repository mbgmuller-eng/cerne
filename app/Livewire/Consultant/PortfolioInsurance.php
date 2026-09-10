<?php

namespace App\Livewire\Consultant;

use App\Enums\InsuranceType;
use App\Models\InsurancePolicy;
use App\Services\ConsultantPortfolioService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Seguros de TODOS os clientes ativos do consultor — agrupados em tipo →
 * cliente → (membro, quando o cliente tem mais de um dono de apólice
 * naquele tipo, ou sempre em Saúde) → seguradora → apólices. Mesmo corte
 * de InsuranceIndex (tela do cliente), só que atravessando vários
 * perfis — "quem mais tem X" fica por tipo, não misturado.
 *
 * O corte por seguradora (filtro `seguradora`) continua existindo —
 * útil pra falar com a seguradora sobre a carteira inteira.
 */
#[Layout('components.layouts.app')]
class PortfolioInsurance extends Component
{
    #[Url]
    public string $seguradora = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function render(ConsultantPortfolioService $portfolio)
    {
        $todas = $portfolio->allActivePolicies(auth()->user());

        $seguradoras = $todas
            ->pluck('policy.insurer_name')
            ->unique()
            ->sort()
            ->values();

        $linhas = $this->seguradora === ''
            ? $todas
            : $todas->filter(fn (array $linha): bool => $linha['policy']->insurer_name === $this->seguradora);

        return view('livewire.consultant.portfolio-insurance', [
            'grouped' => $this->group($linhas),
            'seguradoras' => $seguradoras,
            'totalGeral' => $todas->count(),
        ]);
    }

    /**
     * @param  Collection<int, array{policy: InsurancePolicy, client_name: string, member_name: ?string}>  $linhas
     * @return Collection<int, array{tipo: InsuranceType, clientes: Collection}>
     */
    private function group(Collection $linhas): Collection
    {
        return $linhas
            ->groupBy(fn (array $l) => $l['policy']->insurance_type->value)
            ->map(function (Collection $doTipo, string $tipoValue) {
                $tipo = InsuranceType::from($tipoValue);

                return [
                    'tipo' => $tipo,
                    'clientes' => $doTipo
                        ->groupBy('client_name')
                        ->map(function (Collection $doCliente) use ($tipo) {
                            $separarPorMembro = $tipo === InsuranceType::Saude
                                || $doCliente->pluck('policy.member_id')->unique()->count() > 1;

                            $porMembro = $separarPorMembro
                                ? $doCliente->groupBy(fn (array $l) => $l['policy']->member_id ?? 'familiar')
                                : collect(['todos' => $doCliente]);

                            return [
                                'separarPorMembro' => $separarPorMembro,
                                'membros' => $porMembro->map(fn (Collection $doMembro) => [
                                    'nome' => $doMembro->first()['member_name'],
                                    'seguradoras' => $doMembro->groupBy(fn (array $l) => $l['policy']->insurer_name),
                                ])->values(),
                            ];
                        }),
                ];
            })
            ->sortBy(fn (array $grupo) => $grupo['tipo']->label())
            ->values();
    }
}
