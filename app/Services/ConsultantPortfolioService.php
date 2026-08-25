<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Enums\InsuranceType;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Panorama da carteira do consultor: agregados através de TODOS os
 * clientes ativos vinculados — não o detalhe de um perfil só (isso é
 * DashboardService).
 *
 * Cada consulta atravessa profile_id deliberadamente via
 * withoutProfileScope(), sempre restrita à lista de perfis dos clientes
 * ativos DESTE consultor — nunca a todos os perfis do sistema. Mesmo
 * padrão que FixedBillService/InvoiceService usam para rotinas que
 * legitimamente cruzam perfis (CLAUDE.md, regra 1). O acesso em si já foi
 * autorizado antes de chegar aqui: só um consultor vinculado abre esta
 * tela (ver PortfolioOverview::mount()).
 *
 * A tabela "por cliente" lista TODO vínculo (ativo, pendente, inativo) —
 * é o pipeline do consultor, não só quem já aceitou. Mas só o vínculo
 * ATIVO expõe dado financeiro na linha: um vínculo pendente ou inativo
 * não dá acesso ao perfil (ver FinancialProfilePolicy), então calcular
 * patrimônio/seguro para ele seria ler dado fora do que a política
 * autoriza — a linha mostra "—" nesses casos, de propósito.
 */
class ConsultantPortfolioService
{
    /**
     * @return array{
     *     clientes: array{total: int, ativos: int},
     *     patrimonio: array,
     *     seguro_vida: array{com: int, sem: int},
     *     premios_mes: string,
     *     multiproduto: int,
     *     por_cliente: list<array>,
     * }
     */
    public function overview(User $consultant): array
    {
        $profileIds = $this->activeClientProfileIds($consultant);
        $apolices = $this->activeInsurancePolicies($profileIds);
        $apolicesPorPerfil = $apolices->groupBy('profile_id');

        return [
            'clientes' => [
                'total' => ConsultantClient::query()->where('consultant_id', $consultant->id)->count(),
                'ativos' => $profileIds->count(),
            ],
            'patrimonio' => $this->netWorth($profileIds),
            'seguro_vida' => $this->lifeInsuranceCoverage($apolicesPorPerfil, $profileIds),
            'premios_mes' => Money::sum($apolices->map(fn (InsurancePolicy $p) => $p->normalizedMonthlyCost())),
            'multiproduto' => $apolicesPorPerfil->filter(fn (Collection $grupo) => $grupo->count() >= 2)->count(),
            'por_cliente' => $this->perClientBreakdown($consultant, $profileIds, $apolicesPorPerfil),
        ];
    }

    /**
     * Todas as apólices ativas dos clientes ativos deste consultor, com o
     * nome de quem é o cliente já anexado — para a tela "Seguros da
     * carteira" (uma linha por apólice, não por cliente).
     *
     * @return Collection<int, array{policy: InsurancePolicy, client_name: string}>
     */
    public function allActivePolicies(User $consultant): Collection
    {
        $profiles = $this->activeClientProfiles($consultant);

        if ($profiles->isEmpty()) {
            return collect();
        }

        $nomes = $this->clientNamesByProfile($profiles);

        return InsurancePolicy::withoutProfileScope()
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->active()
            ->with('member')
            ->orderBy('insurer_name')
            ->get()
            ->map(fn (InsurancePolicy $p): array => [
                'policy' => $p,
                'client_name' => $nomes[$p->profile_id] ?? '—',
            ]);
    }

    /**
     * Todos os investimentos ativos dos clientes ativos deste consultor,
     * com o nome do cliente anexado — para "Investimentos da carteira".
     *
     * @return Collection<int, array{investment: InvestmentRecord, client_name: string}>
     */
    public function allActiveInvestments(User $consultant): Collection
    {
        $profiles = $this->activeClientProfiles($consultant);

        if ($profiles->isEmpty()) {
            return collect();
        }

        $nomes = $this->clientNamesByProfile($profiles);

        return InvestmentRecord::withoutProfileScope()
            ->whereIn('profile_id', $profiles->pluck('id'))
            ->active()
            ->with('member')
            ->orderBy('institution')
            ->get()
            ->map(fn (InvestmentRecord $i): array => [
                'investment' => $i,
                'client_name' => $nomes[$i->profile_id] ?? '—',
            ]);
    }

    /** @return Collection<int, string> ids dos perfis dos clientes com vínculo ativo */
    private function activeClientProfileIds(User $consultant): Collection
    {
        return $this->activeClientProfiles($consultant)->pluck('id');
    }

    /** @return Collection<int, FinancialProfile> perfis dos clientes com vínculo ativo, com o dono carregado */
    private function activeClientProfiles(User $consultant): Collection
    {
        $clientUserIds = ConsultantClient::query()
            ->where('consultant_id', $consultant->id)
            ->where('status', ConsultantClientStatus::Active)
            ->pluck('client_id');

        return FinancialProfile::query()
            ->whereIn('owner_user_id', $clientUserIds)
            ->with('owner')
            ->get();
    }

    /**
     * @param  Collection<int, FinancialProfile>  $profiles
     * @return array<string, string> profile_id => nome do dono do perfil
     */
    private function clientNamesByProfile(Collection $profiles): array
    {
        return $profiles->mapWithKeys(fn (FinancialProfile $p) => [$p->id => $p->owner->name])->all();
    }

    /**
     * Todas as apólices ativas dos clientes ativos, buscadas uma vez só —
     * cobertura de vida, prêmio mensal, multiproduto e o selo de
     * seguradoras por cliente derivam todos desta mesma coleção.
     *
     * @param  Collection<int, string>  $profileIds
     * @return Collection<int, InsurancePolicy>
     */
    private function activeInsurancePolicies(Collection $profileIds): Collection
    {
        if ($profileIds->isEmpty()) {
            return collect();
        }

        return InsurancePolicy::withoutProfileScope()
            ->whereIn('profile_id', $profileIds)
            ->active()
            ->get();
    }

    /** @param Collection<int, string> $profileIds */
    private function netWorth(Collection $profileIds): array
    {
        if ($profileIds->isEmpty()) {
            return ['liquido' => '0.00', 'investimentos' => '0.00', 'contas' => '0.00', 'faturas' => '0.00'];
        }

        $investimentos = Money::parse(
            InvestmentRecord::withoutProfileScope()->whereIn('profile_id', $profileIds)->active()->sum('current_amount')
        );

        $contas = Money::parse(
            BankAccount::withoutProfileScope()->whereIn('profile_id', $profileIds)->active()->consolidated()->sum('current_balance')
        );

        $faturas = Money::parse(
            CreditCardInvoice::withoutProfileScope()->whereIn('profile_id', $profileIds)->outstanding()->sum('total_amount')
        );

        $bruto = bcadd($investimentos, $contas, 2);

        return [
            'investimentos' => $investimentos,
            'contas' => $contas,
            'faturas' => $faturas,
            'liquido' => bcsub($bruto, $faturas, 2),
        ];
    }

    /**
     * @param  Collection<string, Collection<int, InsurancePolicy>>  $apolicesPorPerfil
     * @param  Collection<int, string>  $profileIds
     */
    private function lifeInsuranceCoverage(Collection $apolicesPorPerfil, Collection $profileIds): array
    {
        $com = $apolicesPorPerfil
            ->filter(fn (Collection $grupo) => $grupo->contains(fn (InsurancePolicy $p) => $p->insurance_type === InsuranceType::Vida))
            ->count();

        return ['com' => $com, 'sem' => $profileIds->count() - $com];
    }

    /**
     * Uma linha por vínculo — ativo, pendente ou inativo — para a tabela
     * da tela. Só o ativo tem patrimônio/seguros/prêmio calculados.
     *
     * @param  Collection<int, string>  $profileIds
     * @param  Collection<string, Collection<int, InsurancePolicy>>  $apolicesPorPerfil
     * @return list<array{
     *     name: string, email: string, status: ConsultantClientStatus, since: ?\Illuminate\Support\Carbon,
     *     profile_id: ?string, patrimonio: ?string, seguro_vida: bool, insurers: list<string>,
     *     insurance_types: list<string>, tipo_perfil: ?ProfileType, parceiro: ?string,
     *     acessos: ?int, vida_financeira: ?string, premio_mensal: ?string,
     * }>
     */
    private function perClientBreakdown(User $consultant, Collection $profileIds, Collection $apolicesPorPerfil): array
    {
        $links = ConsultantClient::query()
            ->with(['client.ownedProfiles.owner', 'client.ownedProfiles.members.user', 'client.ownedProfiles.accessSettings'])
            ->where('consultant_id', $consultant->id)
            ->orderBy('status')
            ->get();

        $patrimonioPorPerfil = $this->netWorthByProfile($profileIds);

        return $links->map(function (ConsultantClient $link) use ($patrimonioPorPerfil, $apolicesPorPerfil): array {
            $profile = $link->client->ownedProfiles->first();
            $ativo = $link->status === ConsultantClientStatus::Active;
            $profileId = $ativo ? $profile?->id : null;
            $apolicesDoCliente = $profileId ? ($apolicesPorPerfil->get($profileId) ?? collect()) : collect();

            $exibicao = ($profileId && $profile !== null)
                ? $this->resumoDeExibicao($profile)
                : ['name' => $link->client->name, 'tipo_perfil' => null, 'parceiro' => null, 'acessos' => null, 'vida_financeira' => null];

            return [
                'name' => $exibicao['name'],
                'email' => $link->client->email,
                'status' => $link->status,
                'since' => $link->accepted_at,
                'profile_id' => $profileId,
                'patrimonio' => $profileId ? ($patrimonioPorPerfil[$profileId] ?? '0.00') : null,
                'seguro_vida' => $apolicesDoCliente->contains(fn (InsurancePolicy $p) => $p->insurance_type === InsuranceType::Vida),
                'insurers' => $apolicesDoCliente->pluck('insurer_name')->unique()->values()->all(),
                'insurance_types' => $apolicesDoCliente
                    ->map(fn (InsurancePolicy $p) => $p->insurance_type->label())
                    ->unique()->values()->all(),
                'tipo_perfil' => $exibicao['tipo_perfil'],
                'parceiro' => $exibicao['parceiro'],
                'acessos' => $exibicao['acessos'],
                'vida_financeira' => $exibicao['vida_financeira'],
                'premio_mensal' => $profileId
                    ? Money::sum($apolicesDoCliente->map(fn (InsurancePolicy $p) => $p->normalizedMonthlyCost()))
                    : null,
            ];
        })->all();
    }

    /**
     * Nome de exibição do perfil + o contexto de acesso do casal.
     *
     * Regra: o perfil mostra 1 nome quando só o titular tem login; mostra
     * "Fulano e Beltrana" quando o cônjuge também tem login próprio — o
     * nome combinado já comunica "2 acessos" sem precisar de coluna extra.
     * Sobrenome igual? só aparece uma vez ("Roberto e Fernanda
     * Albuquerque"); sobrenomes diferentes, os dois nomes completos
     * ("Roberto Rodrigues e Fernanda Albuquerque").
     *
     * "Vida financeira" só existe quando há 2 acessos — com 1 acesso não
     * há de quem esconder nada ainda. É o preset de profile_access_settings
     * (ver ProfileAccessSettings::preset()): transparente = única (os dois
     * veem 100%), privado ou personalizado = separada.
     *
     * @return array{name: string, tipo_perfil: ProfileType, parceiro: ?string, acessos: int, vida_financeira: ?string}
     */
    private function resumoDeExibicao(FinancialProfile $profile): array
    {
        $tipoPerfil = $profile->profile_type;

        if ($tipoPerfil === ProfileType::Single) {
            return ['name' => $profile->owner->name, 'tipo_perfil' => $tipoPerfil, 'parceiro' => null, 'acessos' => 1, 'vida_financeira' => null];
        }

        $conjuge = $profile->members->firstWhere('role', MemberRole::Secondary);

        if ($conjuge === null || $conjuge->user === null) {
            return ['name' => $profile->owner->name, 'tipo_perfil' => $tipoPerfil, 'parceiro' => $conjuge?->name, 'acessos' => 1, 'vida_financeira' => null];
        }

        $titular = $profile->owner->name;
        $parceiro = $conjuge->user->name;
        $sobrenomeTitular = $this->sobrenome($titular);

        $nome = ($sobrenomeTitular !== '' && $sobrenomeTitular === $this->sobrenome($parceiro))
            ? $this->primeiroNome($titular).' e '.$parceiro
            : $titular.' e '.$parceiro;

        return [
            'name' => $nome,
            'tipo_perfil' => $tipoPerfil,
            'parceiro' => $conjuge->name,
            'acessos' => 2,
            'vida_financeira' => $profile->settings()->preset() === 'transparent' ? 'unica' : 'separada',
        ];
    }

    private function sobrenome(string $nomeCompleto): string
    {
        $partes = explode(' ', trim($nomeCompleto));

        return count($partes) > 1 ? end($partes) : '';
    }

    private function primeiroNome(string $nomeCompleto): string
    {
        return explode(' ', trim($nomeCompleto))[0];
    }

    /**
     * @param  Collection<int, string>  $profileIds
     * @return array<string, string> profile_id => patrimônio líquido
     */
    private function netWorthByProfile(Collection $profileIds): array
    {
        if ($profileIds->isEmpty()) {
            return [];
        }

        $investimentos = InvestmentRecord::withoutProfileScope()
            ->whereIn('profile_id', $profileIds)->active()
            ->selectRaw('profile_id, SUM(current_amount) as total')
            ->groupBy('profile_id')->pluck('total', 'profile_id');

        $contas = BankAccount::withoutProfileScope()
            ->whereIn('profile_id', $profileIds)->active()->consolidated()
            ->selectRaw('profile_id, SUM(current_balance) as total')
            ->groupBy('profile_id')->pluck('total', 'profile_id');

        $faturas = CreditCardInvoice::withoutProfileScope()
            ->whereIn('profile_id', $profileIds)->outstanding()
            ->selectRaw('profile_id, SUM(total_amount) as total')
            ->groupBy('profile_id')->pluck('total', 'profile_id');

        $resultado = [];
        foreach ($profileIds as $id) {
            $bruto = bcadd((string) ($investimentos[$id] ?? '0'), (string) ($contas[$id] ?? '0'), 2);
            $resultado[$id] = bcsub($bruto, (string) ($faturas[$id] ?? '0'), 2);
        }

        return $resultado;
    }
}
