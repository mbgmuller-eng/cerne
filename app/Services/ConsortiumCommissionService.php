<?php

namespace App\Services;

use App\Enums\DebtKind;
use App\Enums\DebtStatus;
use App\Enums\IncomeStatus;
use App\Enums\UserRole;
use App\Models\CommissionRule;
use App\Models\Debt;
use App\Models\DebtCommission;
use App\Models\DebtInstallment;
use App\Models\FinancialProfile;
use App\Models\IncomeEntry;
use App\Models\Insurer;
use App\Models\User;
use App\Support\ClientDisplayName;
use App\Support\ConsortiumSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Comissão de consórcio do consultor: as regras por administradora, os consórcios dos clientes em que ele tem comissão
 * (ele vendeu ou um terceiro vendeu) e a previsão que sai das duas coisas (via ConsortiumCommissionCalculator).
 *
 * O consórcio entra à mão (o Cerne não sabe quem vendeu), só de cliente com vínculo ativo: ler a carta e as parcelas
 * dele depende disso (regra 2 do CLAUDE.md). A previsão é refeita pela rotina diária e a cada mudança, sem duplicar
 * (`origin_key` única) e sem tocar no que já foi recebido ou cancelado.
 */
class ConsortiumCommissionService
{
    public function __construct(
        private readonly ConsortiumCommissionCalculator $calculator,
        private readonly ProfessionalIncomeService $income,
    ) {}

    // --- Regras --------------------------------------------------------------------------------------------------

    /** @return Collection<int, CommissionRule> */
    public function rules(User $professional): Collection
    {
        return CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_CONSORTIUM)->orderBy('counterparty')->get();
    }

    public function ruleExists(User $professional, string $administradora, ?string $ignorarId = null): bool
    {
        return CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_CONSORTIUM)
            ->where('counterparty_key', $this->key($administradora))
            ->when($ignorarId !== null, fn ($q) => $q->where('id', '!=', $ignorarId))->exists();
    }

    /** @param  array{counterparty: string, credit_percent: string, installment_schedule: string, payout_delay_days?: int|string, notes?: ?string}  $dados */
    public function saveRule(User $professional, ?string $id, array $dados): CommissionRule
    {
        $regra = $id !== null
            ? CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_CONSORTIUM)->findOrFail($id)
            : new CommissionRule(['professional_id' => $professional->id, 'kind' => CommissionRule::KIND_CONSORTIUM]);

        $nome = trim($dados['counterparty']);

        $regra->fill([
            'counterparty' => $nome,
            'counterparty_key' => $this->key($nome),
            'type_key' => '*',
            'credit_percent' => $dados['credit_percent'],
            'installment_schedule' => ConsortiumSchedule::normalize($dados['installment_schedule']),
            'payout_delay_days' => (int) ($dados['payout_delay_days'] ?? 0),
            'notes' => ($dados['notes'] ?? '') === '' ? null : $dados['notes'],
        ])->save();

        $this->reconcile($professional);

        return $regra;
    }

    public function setRuleActive(User $professional, string $id, bool $ativo): void
    {
        CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_CONSORTIUM)->findOrFail($id)->update(['is_active' => $ativo]);
        $this->reconcile($professional);
    }

    public function deleteRule(User $professional, string $id): void
    {
        CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_CONSORTIUM)->findOrFail($id)->delete();
        $this->reconcile($professional);
    }

    // --- Consórcios com comissão ---------------------------------------------------------------------------------------

    /**
     * Consórcios em andamento dos clientes ativos que ainda não geram comissão, para o consultor incluir.
     *
     * @return array<string, string> id do consórcio => rótulo
     */
    public function includableDebts(User $professional): array
    {
        $perfis = array_keys($this->income->clientOptions($professional));

        if ($perfis === []) {
            return [];
        }

        $nomes = $this->clientNames($perfis);

        return Debt::withoutGlobalScopes()->whereIn('profile_id', $perfis)->where('kind', DebtKind::Consortium->value)->where('status', DebtStatus::Active->value)
            ->whereNotIn('id', DebtCommission::query()->where('professional_id', $professional->id)->where('is_active', true)->select('debt_id'))
            ->orderBy('creditor')->get()
            ->mapWithKeys(fn (Debt $d) => [$d->id => ($nomes[$d->profile_id] ?? 'Cliente').' · '.($d->creditor ?: $d->name).' · carta '.number_format((float) $d->credit_amount, 2, ',', '.')])
            ->all();
    }

    /**
     * Administradoras dos consórcios dos clientes ativos, para sugerir ao criar a regra.
     *
     * @return list<string>
     */
    public function administratorsOf(User $professional): array
    {
        $perfis = array_keys($this->income->clientOptions($professional));

        if ($perfis === []) {
            return [];
        }

        return Debt::withoutGlobalScopes()->whereIn('profile_id', $perfis)->where('kind', DebtKind::Consortium->value)
            ->whereNotNull('creditor')->where('creditor', '!=', '')->distinct()->orderBy('creditor')->pluck('creditor')->all();
    }

    /** Inclui (ou reativa) o consórcio. Vendeu: vale a regra da administradora. Terceiro: só o percentual informado. */
    public function includeDebt(User $professional, string $debtId, string $papel, ?string $percentual = null, ?string $cronograma = null): DebtCommission
    {
        $consorcio = Debt::withoutGlobalScopes()->where('kind', DebtKind::Consortium->value)->findOrFail($debtId);

        abort_unless(array_key_exists($consorcio->profile_id, $this->income->clientOptions($professional)), 403);

        $vinculo = DebtCommission::query()->updateOrCreate(
            ['professional_id' => $professional->id, 'debt_id' => $consorcio->id],
            $this->linkAttributes($papel, $percentual, $cronograma) + ['is_active' => true],
        );
        $this->reconcile($professional);

        return $vinculo;
    }

    public function updateLink(User $professional, string $id, string $papel, ?string $percentual, ?string $cronograma): void
    {
        DebtCommission::query()->where('professional_id', $professional->id)->findOrFail($id)->update($this->linkAttributes($papel, $percentual, $cronograma));
        $this->reconcile($professional);
    }

    public function setLinkActive(User $professional, string $id, bool $ativo): void
    {
        DebtCommission::query()->where('professional_id', $professional->id)->findOrFail($id)->update(['is_active' => $ativo]);
        $this->reconcile($professional);
    }

    public function deleteLink(User $professional, string $id): void
    {
        DebtCommission::query()->where('professional_id', $professional->id)->findOrFail($id)->delete();
        $this->reconcile($professional);
    }

    /** @return Collection<int, array<string, mixed>> uma linha por consórcio com comissão, com a regra e o total previsto */
    public function linkRows(User $professional): Collection
    {
        $vinculos = DebtCommission::query()->where('professional_id', $professional->id)->with('debt')->get()->filter(fn (DebtCommission $v) => $v->debt !== null);
        $nomes = $this->clientNames($vinculos->pluck('debt.profile_id')->unique()->all());
        $regras = $this->indexedRules($professional);

        return $vinculos->map(function (DebtCommission $v) use ($nomes, $regras): array {
            $d = $v->debt;
            $regra = $regras[$this->key((string) $d->creditor)] ?? null;
            $percentual = $this->calculator->percent($v, $regra);
            $cronograma = $v->schedule_override ?: $regra?->installment_schedule;

            return [
                'id' => $v->id,
                'ativo' => $v->is_active,
                'papel' => $v->role,
                'cliente' => $nomes[$d->profile_id] ?? 'Cliente',
                'administradora' => $d->creditor ?: $d->name,
                'carta' => (string) $d->credit_amount,
                'percentual' => $percentual,
                'cronograma' => $cronograma,
                'percentual_proprio' => $v->percent_override !== null,
                'cronograma_proprio' => filled($v->schedule_override),
                'total' => $percentual !== null ? bcadd(bcdiv(bcmul((string) $d->credit_amount, $percentual, 6), '100', 6), '0.005', 2) : null,
                'regra' => $regra,
                'quitado' => $d->status !== DebtStatus::Active,
            ];
        })->sortBy(fn (array $l) => mb_strtolower($l['cliente']))->values();
    }

    // --- Previsão ------------------------------------------------------------------------------------------------

    /**
     * Refaz a previsão da comissão de consórcio do profissional. Idempotente; recebido e cancelado ficam como estão.
     *
     * @return array{criados: int, atualizados: int, removidos: int}
     */
    public function reconcile(User $professional, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $primeiroMes = $hoje->startOfMonth()->toDateString();
        $resultado = ['criados' => 0, 'atualizados' => 0, 'removidos' => 0];
        $regras = $this->indexedRules($professional);
        $clientesAtivos = $this->income->clientOptions($professional);

        $vinculos = DebtCommission::query()->where('professional_id', $professional->id)->get();
        $consorcios = Debt::withoutGlobalScopes()->whereIn('id', $vinculos->pluck('debt_id'))->get()->keyBy('id');
        $parcelas = DebtInstallment::withoutGlobalScopes()->whereIn('debt_id', $vinculos->pluck('debt_id'))->get()->groupBy('debt_id')
            ->map(fn (Collection $g) => $g->keyBy('number'));

        $existentes = IncomeEntry::query()->ownedBy($professional)->where('origin_key', 'like', 'consortium:%')->get()
            ->groupBy(fn (IncomeEntry $e) => explode(':', (string) $e->origin_key)[1] ?? '');
        $vivos = [];

        foreach ($vinculos as $vinculo) {
            $consorcio = $consorcios->get($vinculo->debt_id);
            $desejados = [];

            if ($consorcio !== null && $vinculo->is_active && $consorcio->status === DebtStatus::Active && array_key_exists($consorcio->profile_id, $clientesAtivos)) {
                $regra = $regras[$this->key((string) $consorcio->creditor)] ?? null;
                $desejados = collect($this->calculator->entries($consorcio, $vinculo, $regra, $parcelas->get($consorcio->id, collect()), $hoje))->keyBy('key')->all();
            }

            $vivos[$vinculo->debt_id] = true;
            $this->apply($professional, $consorcio, $existentes->get($vinculo->debt_id, collect()), $desejados, $primeiroMes, $resultado);
        }

        foreach ($existentes as $debtId => $entradas) {
            if (! isset($vivos[$debtId])) {
                $resultado['removidos'] += $this->removeStaleExpected($entradas, $primeiroMes);
            }
        }

        return $resultado;
    }

    /** Rotina diária: refaz a previsão de todo consultor que tem consórcio com comissão. */
    public function runDailyMaintenance(?CarbonImmutable $hoje = null): array
    {
        $total = ['profissionais' => 0, 'criados' => 0, 'atualizados' => 0, 'removidos' => 0];

        foreach (User::query()->whereIn('id', DebtCommission::query()->select('professional_id'))->where('role', UserRole::Consultant)->orderBy('id')->get() as $consultor) {
            $total['profissionais']++;

            foreach ($this->reconcile($consultor, $hoje) as $campo => $n) {
                $total[$campo] += $n;
            }
        }

        return $total;
    }

    // --- Internos --------------------------------------------------------------------------------------------------

    /** @param  Collection<int, IncomeEntry>  $existentes  @param  array<string, array>  $desejados */
    private function apply(User $professional, ?Debt $consorcio, Collection $existentes, array $desejados, string $primeiroMes, array &$resultado): void
    {
        $porChave = $existentes->keyBy('origin_key');

        foreach ($desejados as $chave => $item) {
            $atual = $porChave->get($chave);

            if ($atual === null) {
                try {
                    IncomeEntry::query()->create([
                        'professional_id' => $professional->id, 'profile_id' => $consorcio->profile_id, 'origin_key' => $chave, 'source' => $item['source'],
                        'status' => IncomeStatus::Expected, 'description' => $item['description'], 'due_on' => $item['due_on'], 'amount' => $item['amount'],
                    ]);
                    $resultado['criados']++;
                } catch (UniqueConstraintViolationException) {
                    // execução paralela já criou
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

    private function removeStaleExpected(Collection $entradas, string $primeiroMes): int
    {
        $ids = $entradas->filter(fn (IncomeEntry $e) => $e->status === IncomeStatus::Expected && $e->due_on->toDateString() >= $primeiroMes)->pluck('id');

        return $ids->isEmpty() ? 0 : IncomeEntry::query()->whereIn('id', $ids)->delete();
    }

    /** @return array<string, CommissionRule> chave da administradora => regra ativa */
    private function indexedRules(User $professional): array
    {
        return $this->rules($professional)->where('is_active', true)->mapWithKeys(fn (CommissionRule $r) => [$r->counterparty_key => $r])->all();
    }

    /** @return array{role: string, percent_override: ?string, schedule_override: ?string} */
    private function linkAttributes(string $papel, ?string $percentual, ?string $cronograma): array
    {
        return [
            'role' => $papel === DebtCommission::ROLE_THIRD_PARTY ? DebtCommission::ROLE_THIRD_PARTY : DebtCommission::ROLE_SOLD,
            'percent_override' => blank($percentual) ? null : $percentual,
            'schedule_override' => blank($cronograma) ? null : ConsortiumSchedule::normalize($cronograma),
        ];
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

    private function key(string $nome): string
    {
        return Insurer::normalizeName($nome);
    }
}
