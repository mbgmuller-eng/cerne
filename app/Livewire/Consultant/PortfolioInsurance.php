<?php

namespace App\Livewire\Consultant;

use App\Enums\InsuranceType;
use App\Enums\InviteStatus;
use App\Models\ConsultantInvite;
use App\Models\InsurancePolicy;
use App\Services\ClientInviteService;
use App\Services\ConsultantLinkService;
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
 *
 * Também é aqui que o CORRETOR vincula um cliente novo — ele não tem a
 * tela de carteira do consultor (PortfolioOverview), então o formulário de
 * convite/pedido de vínculo mora nesta, a única tela que os dois dividem
 * (ver ConsultantLinkService::inviteOrRequest()). Pro consultor o botão
 * fica escondido: ele já tem esse formulário em PortfolioOverview, duas
 * portas pra mesma coisa só confundiriam.
 */
#[Layout('components.layouts.app')]
class PortfolioInsurance extends Component
{
    #[Url]
    public string $seguradora = '';

    public string $inviteName = '';

    public string $inviteEmail = '';

    public ?string $lastInviteLink = null;

    public bool $showInviteForm = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);
    }

    public function toggleInviteForm(): void
    {
        $this->showInviteForm = ! $this->showInviteForm;

        if ($this->showInviteForm) {
            $this->reset('inviteName', 'inviteEmail', 'lastInviteLink');
            $this->resetErrorBag();
        }
    }

    public function invite(ClientInviteService $invites, ConsultantLinkService $links): void
    {
        $this->validate([
            'inviteName' => ['required', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
        ], attributes: [
            'inviteName' => 'nome',
            'inviteEmail' => 'e-mail',
        ]);

        $this->lastInviteLink = $links->inviteOrRequest(auth()->user(), $this->inviteName, $this->inviteEmail, $invites);
        $this->reset('inviteName', 'inviteEmail');
        session()->flash('status', 'Convite enviado.');
    }

    public function reenviarConvite(string $id, ClientInviteService $invites): void
    {
        $convite = ConsultantInvite::query()
            ->where('consultant_id', auth()->id())
            ->where('status', InviteStatus::Pending)
            ->findOrFail($id);

        $this->lastInviteLink = $invites->resend($convite);
        session()->flash('status', 'Convite reenviado.');
    }

    /** @return Collection<int, ConsultantInvite> */
    public function getPendingInvitesProperty(): Collection
    {
        return ConsultantInvite::query()
            ->where('consultant_id', auth()->id())
            ->where('status', InviteStatus::Pending)
            ->latest()
            ->get()
            ->reject(fn (ConsultantInvite $invite) => $invite->isExpired())
            ->values();
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
                                || $doCliente->map(fn (array $l) => $l['policy']->personGroupKey())->unique()->count() > 1;

                            $porMembro = $separarPorMembro
                                ? $doCliente->groupBy(fn (array $l) => $l['policy']->personGroupKey())
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
