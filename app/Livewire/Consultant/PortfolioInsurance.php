<?php

namespace App\Livewire\Consultant;

use App\Enums\InsuranceType;
use App\Enums\InviteStatus;
use App\Models\ConsultantInvite;
use App\Models\InsurancePolicy;
use App\Models\Insurer;
use App\Services\ClientInviteService;
use App\Services\ConsultantCapacityService;
use App\Services\ConsultantLinkService;
use App\Services\ConsultantPortfolioService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Seguros de TODOS os clientes ativos do consultor, no mesmo molde de "Investimentos da carteira": um
 * cartão por cliente, em ordem alfabética e fechado por padrão, com o resumo no cabeçalho (tipos, quantas
 * apólices, capital e custo) e as apólices — cada uma com o seu tipo — só quando se abre o cartão.
 *
 * Quando um cliente tem mais de um dono de apólice (casal), as linhas se separam por pessoa dentro do
 * cartão, senão parecia a mesma apólice repetida (ver PortfolioInsuranceGroupingTest).
 *
 * Filtros (todos na URL, para link e voltar do navegador): busca livre, tipo de seguro, seguradora e
 * situação (vencendo em 30 dias, ou dados incompletos). A seguradora é comparada pelo nome oficial, então
 * "ICATU" e "Icatu Seguros" são a mesma.
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
    public string $busca = '';

    #[Url]
    public string $tipo = '';

    #[Url]
    public string $seguradora = '';

    /** '' = todas, 'vencendo' = vence em até 30 dias, 'incompleta' = sem custo informado. */
    #[Url]
    public string $situacao = '';

    public string $inviteName = '';

    public string $inviteEmail = '';

    public ?string $lastInviteLink = null;

    public bool $showInviteForm = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isLinkedProfessional(), 403);
    }

    public function limparFiltros(): void
    {
        $this->reset('busca', 'tipo', 'seguradora', 'situacao');
    }

    public function toggleInviteForm(): void
    {
        $this->showInviteForm = ! $this->showInviteForm;

        if ($this->showInviteForm) {
            $this->reset('inviteName', 'inviteEmail', 'lastInviteLink');
            $this->resetErrorBag();
        }
    }

    public function invite(ClientInviteService $invites, ConsultantLinkService $links, ConsultantCapacityService $capacity): void
    {
        $this->validate([
            'inviteName' => ['required', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
        ], attributes: [
            'inviteName' => 'nome',
            'inviteEmail' => 'e-mail',
        ]);

        if (! $capacity->hasRoomForNewClient(auth()->user())) {
            $this->addError('inviteEmail', 'Você atingiu o limite de clientes do seu plano atual. Aumente o limite na página de assinatura para adicionar mais.');

            return;
        }

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
        $nomeOficial = Insurer::canonicalizer();

        $todas = $portfolio->allActivePolicies(auth()->user())
            ->map(fn (array $linha): array => $linha + ['seguradora' => $nomeOficial($linha['policy']->insurer_name)]);

        $linhas = $todas->filter(fn (array $linha): bool => $this->passaNosFiltros($linha, $nomeOficial));

        return view('livewire.consultant.portfolio-insurance', [
            'grouped' => $this->group($linhas),
            // Só o que existe na carteira: oferecer um tipo ou seguradora sem nenhuma apólice só leva a tela vazia.
            'seguradoras' => $todas->pluck('seguradora')->unique()->sortBy(fn (string $n) => $this->chave($n))->values(),
            'tipos' => $todas->map(fn (array $l) => $l['policy']->insurance_type)->unique()->sortBy(fn (InsuranceType $t) => $this->chave($t->label()))->values(),
            'totalGeral' => $todas->count(),
            'totalFiltrado' => $linhas->count(),
            'filtrando' => $this->busca !== '' || $this->tipo !== '' || $this->seguradora !== '' || $this->situacao !== '',
            'insurerColors' => Insurer::colorMap(),
        ]);
    }

    /** @param  array{policy: InsurancePolicy, client_name: string, member_name: ?string, seguradora: string}  $linha */
    private function passaNosFiltros(array $linha, \Closure $nomeOficial): bool
    {
        $apolice = $linha['policy'];

        if ($this->tipo !== '' && $apolice->insurance_type->value !== $this->tipo) {
            return false;
        }

        if ($this->seguradora !== '' && $linha['seguradora'] !== $nomeOficial($this->seguradora)) {
            return false;
        }

        if ($this->situacao === 'vencendo' && ! $apolice->isExpiring(30)) {
            return false;
        }

        if ($this->situacao === 'incompleta' && ! $apolice->isMissingCost()) {
            return false;
        }

        if (trim($this->busca) !== '') {
            $texto = $this->chave(implode(' ', [
                $linha['client_name'], $linha['member_name'], $linha['seguradora'], $apolice->insurer_name,
                $apolice->policy_number, preg_replace('/\D/', '', (string) $apolice->policy_number), $apolice->insured_item, $apolice->insurance_type->label(),
            ]));

            // Cada palavra digitada precisa aparecer, em qualquer ordem ("icatu ana" acha a apólice da Ana na Icatu).
            foreach (preg_split('/\s+/', $this->chave($this->busca), -1, PREG_SPLIT_NO_EMPTY) as $palavra) {
                if (! str_contains($texto, $palavra)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Um item por cliente, em ordem alfabética (sem diferença de acento ou maiúscula).
     *
     * @param  Collection<int, array{policy: InsurancePolicy, client_name: string, member_name: ?string, seguradora: string}>  $linhas
     * @return Collection<string, array{profile_id: string, quantidade: int, cobertura: string, mensal: string, tipos: Collection, seguradoras: Collection, vencendo: int, incompletas: int, separarPorPessoa: bool, pessoas: Collection}>
     */
    private function group(Collection $linhas): Collection
    {
        return $linhas
            ->groupBy('client_name')
            ->sortKeysUsing(fn (string $a, string $b) => strcmp($this->chave($a), $this->chave($b)))
            ->map(function (Collection $doCliente) {
                $ordenadas = $doCliente->sortBy(fn (array $l) => implode('|', [
                    $this->chave($l['policy']->insurance_type->label()),
                    $this->chave($l['seguradora']),
                    $l['policy']->policy_number,
                ]))->values();

                $separarPorPessoa = $ordenadas->map(fn (array $l) => $l['policy']->personGroupKey())->unique()->count() > 1;

                $pessoas = $separarPorPessoa
                    ? $ordenadas->groupBy(fn (array $l) => $l['policy']->personGroupKey())
                        ->map(fn (Collection $dela) => ['nome' => $dela->first()['member_name'], 'linhas' => $dela])
                        // "Seguro familiar" (sem dono) por último.
                        ->sortBy(fn (array $p) => $p['nome'] === null ? 'zzzz' : $this->chave($p['nome']))
                        ->values()
                    : collect([['nome' => null, 'linhas' => $ordenadas]]);

                return [
                    'profile_id' => $ordenadas->first()['policy']->profile_id,
                    'quantidade' => $ordenadas->count(),
                    'cobertura' => Money::sum($ordenadas->map(fn (array $l) => $l['policy']->coverage_amount)),
                    'mensal' => Money::sum($ordenadas->map(fn (array $l) => $l['policy']->normalizedMonthlyCost())),
                    'tipos' => $ordenadas->map(fn (array $l) => $l['policy']->insurance_type)->unique()->sortBy(fn (InsuranceType $t) => $this->chave($t->label()))->values(),
                    'seguradoras' => $ordenadas->pluck('seguradora')->unique()->sortBy(fn (string $n) => $this->chave($n))->values(),
                    'vencendo' => $ordenadas->filter(fn (array $l) => $l['policy']->isExpiring(30))->count(),
                    'incompletas' => $ordenadas->filter(fn (array $l) => $l['policy']->isMissingCost())->count(),
                    'separarPorPessoa' => $separarPorPessoa,
                    'pessoas' => $pessoas,
                ];
            });
    }

    /** Chave de comparação e ordenação: minúscula e sem acento ("Álvaro" e "alvaro" são iguais). */
    private function chave(?string $texto): string
    {
        return Str::lower(Str::ascii((string) $texto));
    }
}
