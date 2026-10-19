<?php

namespace App\Services;

use App\Enums\CommissionType;
use App\Enums\IncomeStatus;
use App\Enums\UserRole;
use App\Models\CommissionRule;
use App\Models\FinancialProfile;
use App\Models\IncomeEntry;
use App\Models\Insurer;
use App\Models\InsurancePolicy;
use App\Models\PolicyCommission;
use App\Models\User;
use App\Support\ClientDisplayName;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comissão e agenciamento de seguros do profissional: as regras por seguradora, as apólices em que ele tem comissão e a
 * previsão que sai das duas coisas (via InsuranceCommissionCalculator).
 *
 * O que sai daqui é sempre PREVISTO: o Cerne não sabe se o cliente pagou a parcela nem se a seguradora repassou, então
 * quem recebe confirma na tela de Recebimentos. A previsão é refeita pela rotina diária e a cada mudança de regra, sem
 * duplicar (chave `origin_key` única) e sem tocar no que já foi recebido ou cancelado.
 *
 * Lê apólices de vários perfis de propósito (por isso `withoutGlobalScopes()`): o recorte é o que o profissional vendeu
 * (corretor vinculado ou cotação de um lead dele) ou o que o consultor incluiu entre as apólices dos seus clientes ativos.
 */
class InsuranceCommissionService
{
    public function __construct(
        private readonly InsuranceCommissionCalculator $calculator,
        private readonly ProfessionalIncomeService $income,
    ) {}

    // --- Regras --------------------------------------------------------------------------------------------------

    /** @return Collection<int, CommissionRule> */
    public function rules(User $professional): Collection
    {
        return CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_INSURANCE)
            ->orderBy('counterparty')->orderBy('type_key')->get();
    }

    /** A regra já existe para essa seguradora e esse tipo? (usa a mesma chave do índice único) */
    public function ruleExists(User $professional, string $seguradora, ?string $tipo, ?string $ignorarId = null): bool
    {
        return CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_INSURANCE)
            ->where('counterparty_key', $this->key($seguradora))->where('type_key', $tipo ?: '*')
            ->when($ignorarId !== null, fn ($q) => $q->where('id', '!=', $ignorarId))->exists();
    }

    public function saveRule(User $professional, ?string $id, array $dados): CommissionRule
    {
        $rule = $id !== null
            ? CommissionRule::query()->ownedBy($professional)->findOrFail($id)
            : new CommissionRule(['professional_id' => $professional->id, 'kind' => CommissionRule::KIND_INSURANCE]);

        $nome = (self::canonicalizer())($dados['counterparty']);
        $tipoAgenciamento = CommissionType::tryFrom((string) ($dados['agency_type'] ?? ''));

        $rule->fill([
            'counterparty' => $nome,
            'counterparty_key' => $this->key($nome),
            'insurance_type' => $dados['insurance_type'] ?: null,
            'type_key' => $dados['insurance_type'] ?: '*',
            'first_year_percent' => $dados['first_year_percent'],
            'renewal_percent' => $this->blank($dados['renewal_percent'] ?? null),
            'agency_type' => $tipoAgenciamento,
            'agency_percent' => $tipoAgenciamento === CommissionType::Percent ? $this->blank($dados['agency_percent'] ?? null) : null,
            'agency_amount' => $tipoAgenciamento === CommissionType::Amount ? $this->blank($dados['agency_amount'] ?? null) : null,
            'payout_delay_days' => (int) ($dados['payout_delay_days'] ?? 0),
            'notes' => $this->blank($dados['notes'] ?? null),
        ])->save();

        $this->reconcile($professional);

        return $rule;
    }

    public function setRuleActive(User $professional, string $id, bool $ativo): void
    {
        CommissionRule::query()->ownedBy($professional)->findOrFail($id)->update(['is_active' => $ativo]);
        $this->reconcile($professional);
    }

    public function deleteRule(User $professional, string $id): void
    {
        CommissionRule::query()->ownedBy($professional)->findOrFail($id)->delete();
        $this->reconcile($professional);
    }

    // --- Apólices ------------------------------------------------------------------------------------------------

    /**
     * Cria o vínculo das apólices que o profissional vendeu. Não reativa um vínculo que ele desligou.
     *
     * @return int quantos vínculos novos nasceram
     */
    public function linkSoldPolicies(User $professional): int
    {
        $vendidas = InsurancePolicy::withoutGlobalScopes()->where('is_active', true)
            ->where(function ($q) use ($professional): void {
                $q->where('broker_id', $professional->id)
                    ->orWhereIn('source_lead_quote_id', DB::table('lead_quotes')
                        ->whereIn('lead_id', DB::table('leads')->where('consultant_id', $professional->id)->select('id'))
                        ->select('id'));
            })
            ->whereNotIn('id', PolicyCommission::query()->where('professional_id', $professional->id)->select('insurance_policy_id'))
            ->pluck('id');

        $criados = 0;

        foreach ($vendidas as $policyId) {
            try {
                PolicyCommission::query()->create(['professional_id' => $professional->id, 'insurance_policy_id' => $policyId, 'origin' => 'auto']);
                $criados++;
            } catch (UniqueConstraintViolationException) {
                // outra execução já criou
            }
        }

        return $criados;
    }

    /** Consultor inclui uma apólice de um cliente ativo dele que não nasceu de uma venda registrada no Cerne. */
    public function includePolicy(User $professional, string $policyId): PolicyCommission
    {
        $this->includePolicies($professional, [$policyId]);

        return PolicyCommission::query()->where('professional_id', $professional->id)->where('insurance_policy_id', $policyId)->firstOrFail();
    }

    /**
     * Inclui várias apólices de uma vez (ou reativa as que ele tinha desligado) e refaz a previsão uma vez só. Todas
     * precisam ser de clientes ativos do consultor: uma fora disso recusa o lote inteiro.
     *
     * @param  list<string>  $policyIds
     * @return int quantas apólices ficaram com comissão
     */
    public function includePolicies(User $professional, array $policyIds): int
    {
        $ids = array_values(array_unique($policyIds));

        if ($ids === []) {
            return 0;
        }

        $apolices = InsurancePolicy::withoutGlobalScopes()->where('is_active', true)->whereIn('id', $ids)->get();
        $clientes = $this->income->clientOptions($professional);

        abort_unless($apolices->count() === count($ids) && $apolices->every(fn (InsurancePolicy $p) => array_key_exists($p->profile_id, $clientes)), 403);

        foreach ($apolices as $apolice) {
            $vinculo = PolicyCommission::query()->firstOrCreate(
                ['professional_id' => $professional->id, 'insurance_policy_id' => $apolice->id],
                ['origin' => 'manual'],
            );
            $vinculo->update(['is_active' => true]);
        }

        $this->reconcile($professional);

        return $apolices->count();
    }
    public function setLinkActive(User $professional, string $id, bool $ativo): void
    {
        PolicyCommission::query()->where('professional_id', $professional->id)->findOrFail($id)->update(['is_active' => $ativo]);
        $this->reconcile($professional);
    }

    /** @return Collection<int, array<string, mixed>> uma linha por apólice com comissão, já com a regra que vale para ela */
    public function policyRows(User $professional): Collection
    {
        $vinculos = PolicyCommission::query()->where('professional_id', $professional->id)->with('policy')->get()
            ->filter(fn (PolicyCommission $v) => $v->policy !== null);

        $nomes = $this->clientNames($vinculos->pluck('policy.profile_id')->unique()->all());
        $regras = $this->indexedRules($professional);
        $canon = self::canonicalizer();

        return $vinculos->map(function (PolicyCommission $v) use ($nomes, $regras, $canon): array {
            $p = $v->policy;

            return [
                'id' => $v->id,
                'origem' => $v->origin,
                'ativo' => $v->is_active,
                'cliente' => $nomes[$p->profile_id] ?? 'Cliente',
                'seguradora' => $canon((string) $p->insurer_name),
                'tipo' => $p->insurance_type->label(),
                'numero' => $p->policy_number,
                'parcela' => (string) $p->monthly_premium,
                'periodicidade' => $p->payment_frequency->label(),
                'apolice_ativa' => $p->is_active,
                'regra' => $this->resolveRule($regras, $canon((string) $p->insurer_name), $p->insurance_type->value),
            ];
        })->sortBy(fn (array $l) => mb_strtolower($l['cliente']))->values();
    }

    /**
     * Seguradoras de apólices com comissão que ainda não têm regra: sem ela o Cerne não tem como prever.
     *
     * @return list<string>
     */
    public function insurersWithoutRule(User $professional): array
    {
        return $this->policyRows($professional)
            ->filter(fn (array $l) => $l['ativo'] && $l['apolice_ativa'] && $l['regra'] === null)
            ->pluck('seguradora')->unique()->sort()->values()->all();
    }

    /**
     * Apólices de clientes ativos do consultor que ainda não geram comissão, para ele incluir à mão.
     *
     * @return array<string, string> id da apólice => rótulo
     */
    public function includablePolicies(User $professional): array
    {
        $perfis = array_keys($this->income->clientOptions($professional));

        if ($perfis === []) {
            return [];
        }

        $nomes = $this->clientNames($perfis);

        return InsurancePolicy::withoutGlobalScopes()->whereIn('profile_id', $perfis)->where('is_active', true)->withRealData()
            ->whereNotIn('id', PolicyCommission::query()->where('professional_id', $professional->id)->where('is_active', true)->select('insurance_policy_id'))
            ->orderBy('insurer_name')->get()
            ->mapWithKeys(fn (InsurancePolicy $p) => [$p->id => ($nomes[$p->profile_id] ?? 'Cliente').' · '.$p->insurer_name.' · '.$p->insurance_type->label().(filled($p->policy_number) ? ' · '.$p->policy_number : '')])
            ->all();
    }

    // --- Previsão ------------------------------------------------------------------------------------------------

    /**
     * Refaz a previsão de comissão e agenciamento do profissional. Idempotente: cria o que falta, corrige o valor do
     * que ainda é previsão e tira a previsão que deixou de valer. Recebido e cancelado ficam como estão.
     *
     * @return array{criados: int, atualizados: int, removidos: int}
     */
    public function reconcile(User $professional, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $primeiroMes = $hoje->startOfMonth()->toDateString();
        $regras = $this->indexedRules($professional);
        $canon = self::canonicalizer();
        $resultado = ['criados' => 0, 'atualizados' => 0, 'removidos' => 0];

        $vinculos = PolicyCommission::query()->where('professional_id', $professional->id)->get();
        $apolices = InsurancePolicy::withoutGlobalScopes()->whereIn('id', $vinculos->pluck('insurance_policy_id'))->get()->keyBy('id');

        $existentes = IncomeEntry::query()->ownedBy($professional)->where('origin_key', 'like', 'policy:%')->get()->groupBy(
            fn (IncomeEntry $e) => explode(':', (string) $e->origin_key)[1] ?? '',
        );

        $vivos = [];

        foreach ($vinculos as $vinculo) {
            $apolice = $apolices->get($vinculo->insurance_policy_id);
            $desejados = [];

            if ($apolice !== null && $vinculo->is_active && $apolice->is_active) {
                $regra = $this->resolveRule($regras, $canon((string) $apolice->insurer_name), $apolice->insurance_type->value);
                $desejados = $regra !== null ? collect($this->calculator->entries($apolice, $regra, $hoje))->keyBy('key')->all() : [];
            }

            $vivos[$vinculo->insurance_policy_id] = true;
            $this->apply($professional, $apolice, $existentes->get($vinculo->insurance_policy_id, collect()), $desejados, $primeiroMes, $resultado);
        }

        // Previsão de apólice que não tem mais vínculo (a apólice foi apagada ou o vínculo removido).
        foreach ($existentes as $policyId => $entradas) {
            if (! isset($vivos[$policyId])) {
                $resultado['removidos'] += $this->removeStaleExpected($entradas, $primeiroMes);
            }
        }

        return $resultado;
    }

    /** Rotina diária: vincula o que cada profissional vendeu e refaz a previsão. */
    public function runDailyMaintenance(?CarbonImmutable $hoje = null): array
    {
        $total = ['profissionais' => 0, 'criados' => 0, 'atualizados' => 0, 'removidos' => 0, 'vinculos_novos' => 0];

        foreach (User::query()->whereIn('role', [UserRole::Broker, UserRole::Consultant])->where('is_active', true)->orderBy('id')->get() as $profissional) {
            $total['profissionais']++;
            $total['vinculos_novos'] += $this->linkSoldPolicies($profissional);

            foreach ($this->reconcile($profissional, $hoje) as $campo => $n) {
                $total[$campo] += $n;
            }
        }

        return $total;
    }

    // --- Internos ------------------------------------------------------------------------------------------------

    /** @param  Collection<int, IncomeEntry>  $existentes  @param  array<string, array>  $desejados */
    private function apply(User $professional, ?InsurancePolicy $apolice, Collection $existentes, array $desejados, string $primeiroMes, array &$resultado): void
    {
        $porChave = $existentes->keyBy('origin_key');

        foreach ($desejados as $chave => $item) {
            $atual = $porChave->get($chave);

            if ($atual === null) {
                try {
                    IncomeEntry::query()->create([
                        'professional_id' => $professional->id, 'profile_id' => $apolice->profile_id, 'origin_key' => $chave, 'source' => $item['source'],
                        'status' => IncomeStatus::Expected, 'description' => $item['description'], 'due_on' => $item['due_on'], 'amount' => $item['amount'],
                    ]);
                    $resultado['criados']++;
                } catch (UniqueConstraintViolationException) {
                    // já existe (execução paralela)
                }

                continue;
            }

            if ($atual->status === IncomeStatus::Expected
                && ($atual->amount !== $item['amount'] || $atual->due_on->toDateString() !== $item['due_on'] || $atual->description !== $item['description'])) {
                $atual->update(['amount' => $item['amount'], 'due_on' => $item['due_on'], 'description' => $item['description']]);
                $resultado['atualizados']++;
            }
        }

        $sobras = $existentes->filter(fn (IncomeEntry $e) => ! isset($desejados[$e->origin_key]));
        $resultado['removidos'] += $this->removeStaleExpected($sobras, $primeiroMes);
    }

    /** Só some previsão do mês atual em diante: o passado previsto e não confirmado é do profissional decidir. */
    private function removeStaleExpected(Collection $entradas, string $primeiroMes): int
    {
        $ids = $entradas->filter(fn (IncomeEntry $e) => $e->status === IncomeStatus::Expected && $e->due_on->toDateString() >= $primeiroMes)->pluck('id');

        return $ids->isEmpty() ? 0 : IncomeEntry::query()->whereIn('id', $ids)->delete();
    }

    /** @return array<string, CommissionRule> "chave da seguradora|tipo" => regra ativa */
    private function indexedRules(User $professional): array
    {
        return $this->rules($professional)->where('is_active', true)
            ->mapWithKeys(fn (CommissionRule $r) => [$r->counterparty_key.'|'.$r->type_key => $r])->all();
    }

    /** A regra do tipo de seguro vale mais que a da seguradora inteira. */
    private function resolveRule(array $regras, string $seguradora, string $tipo): ?CommissionRule
    {
        $chave = $this->key($seguradora);

        return $regras["{$chave}|{$tipo}"] ?? $regras["{$chave}|*"] ?? null;
    }

    /**
     * @param  list<string>  $profileIds
     * @return array<string, string>
     */
    private function clientNames(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        return FinancialProfile::query()->whereIn('id', $profileIds)->with(['owner', 'members.user'])->get()
            ->mapWithKeys(fn (FinancialProfile $p) => [$p->id => ClientDisplayName::forProfile($p)])->all();
    }

    private static function canonicalizer(): \Closure
    {
        return Insurer::canonicalizer();
    }

    private function key(string $nome): string
    {
        return Insurer::normalizeName($nome);
    }

    private function blank(mixed $valor): mixed
    {
        return $valor === '' ? null : $valor;
    }
}
