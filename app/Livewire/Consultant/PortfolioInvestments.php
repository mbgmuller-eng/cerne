<?php

namespace App\Livewire\Consultant;

use App\Models\InvestmentRecord;
use App\Services\ConsultantPortfolioService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Investimentos de TODOS os clientes ativos do consultor, agrupados em
 * cliente → (membro, quando há mais de um dono no mesmo cliente) →
 * instituição → ativos — mesmo corte de PortfolioInsurance, só que sem
 * o nível de "tipo" (não fazia sentido aqui: o filtro relevante já é
 * por instituição, que vira o nível interno de agrupamento).
 */
#[Layout('components.layouts.app')]
class PortfolioInvestments extends Component
{
    #[Url]
    public string $instituicao = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function render(ConsultantPortfolioService $portfolio)
    {
        $todos = $portfolio->allActiveInvestments(auth()->user());

        $instituicoes = $todos
            ->pluck('investment.institution')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $linhas = $this->instituicao === ''
            ? $todos
            : $todos->filter(fn (array $linha): bool => $linha['investment']->institution === $this->instituicao);

        return view('livewire.consultant.portfolio-investments', [
            'grouped' => $this->group($linhas),
            'instituicoes' => $instituicoes,
            'totalGeral' => $todos->count(),
        ]);
    }

    /**
     * `quantidade`/`total` ficam no resumo do card — dá pra saber o
     * tamanho da carteira daquele cliente sem precisar abrir o
     * acordeão (ver getGroupedProperty() equivalente em InsuranceIndex).
     *
     * @param  Collection<int, array{investment: InvestmentRecord, client_name: string, member_name: ?string}>  $linhas
     * @return Collection<string, array{separarPorMembro: bool, quantidade: int, total: string, profile_id: string, membros: Collection}>
     */
    private function group(Collection $linhas): Collection
    {
        return $linhas
            ->groupBy('client_name')
            ->sortKeys()
            ->map(function (Collection $doCliente) {
                $separarPorMembro = $doCliente->map(fn (array $l) => $l['investment']->member_id)->unique()->count() > 1;

                $porMembro = $separarPorMembro
                    ? $doCliente->groupBy(fn (array $l) => $l['investment']->member_id)
                    : collect(['todos' => $doCliente]);

                return [
                    'separarPorMembro' => $separarPorMembro,
                    'quantidade' => $doCliente->count(),
                    'total' => Money::sum($doCliente->map(fn (array $l) => $l['investment']->current_amount)),
                    // Mesmo perfil pra qualquer ativo deste cliente — dá pra
                    // pegar de qualquer linha. Usado pro botão único "Abrir
                    // perfil" no cabeçalho do card, em vez de repetir um
                    // por ativo (era sempre o mesmo destino).
                    'profile_id' => $doCliente->first()['investment']->profile_id,
                    'membros' => $porMembro->map(fn (Collection $doMembro) => [
                        'nome' => $doMembro->first()['member_name'],
                        'instituicoes' => $doMembro->groupBy(fn (array $l) => $l['investment']->institution ?? 'Sem instituição'),
                    ])->values(),
                ];
            });
    }
}
