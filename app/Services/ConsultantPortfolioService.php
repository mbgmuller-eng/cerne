<?php

namespace App\Services;

use App\Enums\ConsultantClientStatus;
use App\Enums\InsuranceType;
use App\Enums\MemberRole;
use App\Enums\ProfileType;
use App\Models\BankAccount;
use App\Models\ConsultantClient;
use App\Models\CreditCard;
use App\Models\CreditCardInvoice;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\InvestmentSnapshot;
use App\Models\Scopes\MemberPrivacyScope;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
     *     sem_seguro_vida: list<array{profile_id: string, name: string, email: string, patrimonio: string, since: ?\Illuminate\Support\Carbon}>,
     *     evolucao_investido: list<array{rotulo: string, valor: string}>,
     *     acoes_pendentes: array,
     *     distribuicao: array{individual: int, casal: int, vida_financeira: array{unica: int, separada: int}},
     * }
     */
    public function overview(User $consultant): array
    {
        $profiles = $this->activeClientProfiles($consultant);
        $profileIds = $profiles->pluck('id');
        $apolices = $this->activeInsurancePolicies($profileIds);
        $apolicesPorPerfil = $apolices->groupBy('profile_id');
        $porCliente = $this->perClientBreakdown($consultant, $profileIds, $apolicesPorPerfil);

        return [
            'clientes' => [
                'total' => ConsultantClient::query()->where('consultant_id', $consultant->id)->count(),
                'ativos' => $profileIds->count(),
            ],
            'patrimonio' => $this->netWorth($profileIds),
            'seguro_vida' => $this->lifeInsuranceCoverage($apolicesPorPerfil, $profileIds),
            'premios_mes' => Money::sum($apolices->map(fn (InsurancePolicy $p) => $p->normalizedMonthlyCost())),
            'multiproduto' => $apolicesPorPerfil->filter(fn (Collection $grupo) => $grupo->count() >= 2)->count(),
            'por_cliente' => $porCliente,
            'sem_seguro_vida' => $this->clientsWithoutLifeInsurance($porCliente),
            'evolucao_investido' => $this->investedEvolution($profileIds),
            'acoes_pendentes' => $this->pendingActions($consultant, $profiles),
            'distribuicao' => $this->distribution($porCliente),
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

    /**
     * Clientes ativos sem nenhuma apólice de vida vigente — lista acionável
     * (quem contatar), não só a contagem que já vai em seguro_vida.sem.
     * Deriva de $porCliente (já calculado) em vez de reconsultar: é o
     * mesmo dado, só filtrado — e chega com patrimônio/data de vínculo de
     * graça, pra a tela poder ordenar por eles.
     *
     * @param  list<array>  $porCliente
     * @return list<array{profile_id: string, name: string, email: string, patrimonio: string, since: ?\Illuminate\Support\Carbon}>
     */
    private function clientsWithoutLifeInsurance(array $porCliente): array
    {
        return collect($porCliente)
            ->filter(fn (array $l) => $l['status'] === ConsultantClientStatus::Active && ! $l['seguro_vida'])
            ->map(fn (array $l) => [
                'profile_id' => $l['profile_id'],
                'name' => $l['name'],
                'email' => $l['email'],
                'patrimonio' => $l['patrimonio'],
                'since' => $l['since'],
            ])
            ->values()
            ->all();
    }

    /**
     * Patrimônio investido dos últimos N meses, somado através de todos os
     * clientes ativos. Não é o patrimônio líquido inteiro — contas e
     * faturas não têm snapshot histórico (só InvestmentSnapshot existe),
     * então "evolução do patrimônio" aqui é honestamente só a parte que dá
     * pra reconstruir mês a mês.
     *
     * @param  Collection<int, string>  $profileIds
     * @return list<array{rotulo: string, valor: string}>
     */
    private function investedEvolution(Collection $profileIds): array
    {
        $meses = config('cerne.dashboard.evolution_months');
        $inicio = CarbonImmutable::now()->startOfMonth()->subMonths($meses - 1);

        $totais = $profileIds->isEmpty() ? collect() : InvestmentSnapshot::withoutProfileScope()
            ->whereIn('profile_id', $profileIds)
            ->where(function ($q) use ($inicio): void {
                $q->where('year', '>', $inicio->year)
                    ->orWhere(function ($q2) use ($inicio): void {
                        $q2->where('year', $inicio->year)->where('month', '>=', $inicio->month);
                    });
            })
            ->selectRaw('year, month, SUM(amount) as total')
            ->groupBy('year', 'month')
            ->get()
            ->mapWithKeys(fn ($linha) => [$linha->year.'-'.$linha->month => $linha->total]);

        return collect(range(0, $meses - 1))->map(function (int $i) use ($inicio, $totais): array {
            $mes = $inicio->addMonths($i);

            return [
                'rotulo' => $mes->translatedFormat('M/y'),
                'valor' => Money::parse($totais[$mes->year.'-'.$mes->month] ?? 0),
            ];
        })->all();
    }

    /**
     * Vínculos pendentes (pipeline de convite, ordenados do mais antigo pro
     * mais novo) e faturas vencendo nos próximos dias, através da carteira
     * inteira — pra saber onde agir sem abrir cliente por cliente.
     *
     * @param  Collection<int, FinancialProfile>  $profiles
     * @return array{vinculos: list<array{name: string, email: string, dias: int}>, faturas: list<array>, total_faturas: string, dias: int}
     */
    private function pendingActions(User $consultant, Collection $profiles): array
    {
        $vinculos = ConsultantClient::query()
            ->with('client')
            ->where('consultant_id', $consultant->id)
            ->where('status', ConsultantClientStatus::Pending)
            ->orderBy('invited_at')
            ->get()
            ->map(fn (ConsultantClient $c) => [
                'name' => $c->client->name,
                'email' => $c->client->email,
                'dias' => (int) ceil($c->invited_at->diffInDays(now())),
            ])
            ->all();

        $dias = config('cerne.dashboard.upcoming_bills_days');
        $profileIds = $profiles->pluck('id');
        $faturas = collect();

        if ($profileIds->isNotEmpty()) {
            $nomes = $this->clientNamesByProfile($profiles);
            $limite = CarbonImmutable::now()->addDays($dias);

            $faturas = CreditCardInvoice::withoutProfileScope()
                ->whereIn('profile_id', $profileIds)
                ->outstanding()
                ->whereDate('due_date', '>=', now()->toDateString())
                ->whereDate('due_date', '<=', $limite->toDateString())
                ->orderBy('due_date')
                ->get();

            // O cartão também é BelongsToProfile (e RespectsMemberPrivacy):
            // um ->with('creditCard') comum herdaria o escopo do cartão, que
            // falha fechado sem perfil ativo — o consultor não tem nenhum
            // aberto nesta tela. Busca à parte, com os dois escopos
            // removidos deliberadamente (mesmo motivo do withoutProfileScope
            // no topo da classe).
            $nomesCartao = CreditCard::withoutProfileScope()
                ->withoutGlobalScope(MemberPrivacyScope::class)
                ->whereIn('id', $faturas->pluck('credit_card_id'))
                ->pluck('card_name', 'id');

            $faturas = $faturas
                ->map(fn (CreditCardInvoice $f) => [
                    'cliente' => $nomes[$f->profile_id] ?? '—',
                    'nome' => 'Fatura '.($nomesCartao[$f->credit_card_id] ?? '—'),
                    'valor' => $f->total_amount,
                    'vencimento' => $f->due_date->format('d/m'),
                ]);
        }

        return [
            'vinculos' => $vinculos,
            'faturas' => $faturas->values()->all(),
            'total_faturas' => Money::sum($faturas->pluck('valor')),
            'dias' => $dias,
        ];
    }

    /**
     * Perfil individual vs. casal, e entre casais, vida financeira única
     * vs. separada — só entre clientes ATIVOS (a carteira de fato, não o
     * pipeline de convites).
     *
     * @param  list<array>  $porCliente
     * @return array{individual: int, casal: int, vida_financeira: array{unica: int, separada: int}}
     */
    private function distribution(array $porCliente): array
    {
        $ativos = collect($porCliente)->filter(fn (array $l) => $l['status'] === ConsultantClientStatus::Active);
        $individual = $ativos->filter(fn (array $l) => $l['tipo_perfil'] === ProfileType::Single)->count();

        return [
            'individual' => $individual,
            'casal' => $ativos->count() - $individual,
            'vida_financeira' => [
                'unica' => $ativos->filter(fn (array $l) => $l['vida_financeira'] === 'unica')->count(),
                'separada' => $ativos->filter(fn (array $l) => $l['vida_financeira'] === 'separada')->count(),
            ],
        ];
    }
}
