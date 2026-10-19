<?php

namespace App\Services;

use App\Enums\AssetFeeMode;
use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Enums\InvestmentRuleScope;
use App\Enums\UserRole;
use App\Models\AssetFeeAgreement;
use App\Models\CommissionRule;
use App\Models\IncomeEntry;
use App\Models\InvestmentRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Percentual sobre patrimônio do consultor: os contratos por cliente, as regras por produto, empresa ou tipo de ativo e a
 * previsão que sai deles (via AssetFeeCalculator).
 *
 * O valor é uma ESTIMATIVA com o patrimônio de hoje e o profissional confirma o que de fato recebeu. A previsão é refeita
 * pela rotina diária e a cada mudança, sem duplicar (`origin_key` única) e sem tocar no que já foi recebido ou cancelado.
 * Cobrança cujo período já terminou deixa de ser recalculada: o valor fica o último estimado até o profissional dar baixa.
 *
 * Só cliente com vínculo ativo gera previsão: ler o patrimônio dele depende disso (regra 2 do CLAUDE.md).
 */
class AssetFeeService
{
    public function __construct(
        private readonly AssetFeeCalculator $calculator,
        private readonly ProfessionalIncomeService $income,
    ) {}

    // --- Contratos -------------------------------------------------------------------------------------------------

    /** @return Collection<int, AssetFeeAgreement> */
    public function agreements(User $professional): Collection
    {
        return AssetFeeAgreement::query()->where('professional_id', $professional->id)
            ->with(['profile.owner', 'profile.members.user'])
            ->orderByDesc('is_active')->orderBy('created_at')->get();
    }

    public function saveAgreement(User $professional, ?string $id, array $dados): AssetFeeAgreement
    {
        $contrato = $id !== null
            ? AssetFeeAgreement::query()->where('professional_id', $professional->id)->findOrFail($id)
            : new AssetFeeAgreement(['professional_id' => $professional->id]);

        $modo = AssetFeeMode::from($dados['mode']);

        $contrato->fill([
            'profile_id' => $dados['profile_id'],
            'period' => $dados['period'],
            'mode' => $modo,
            'total_percent' => $modo === AssetFeeMode::Total ? $dados['total_percent'] : null,
            'starts_on' => $dados['starts_on'],
            'ends_on' => ($dados['ends_on'] ?? '') === '' ? null : $dados['ends_on'],
            'notes' => ($dados['notes'] ?? '') === '' ? null : $dados['notes'],
            'is_active' => $dados['is_active'] ?? true,
        ])->save();

        $this->reconcile($professional);

        return $contrato;
    }

    public function setAgreementActive(User $professional, string $id, bool $ativo): void
    {
        AssetFeeAgreement::query()->where('professional_id', $professional->id)->findOrFail($id)->update(['is_active' => $ativo]);
        $this->reconcile($professional);
    }

    public function deleteAgreement(User $professional, string $id): void
    {
        AssetFeeAgreement::query()->where('professional_id', $professional->id)->findOrFail($id)->delete();
        $this->reconcile($professional);
    }

    /**
     * Quanto este contrato rende hoje: o patrimônio que paga, o valor por ano e o de cada cobrança.
     *
     * @return array{base: string, annual: string, per_charge: string}
     */
    public function preview(AssetFeeAgreement $contrato, ?Collection $regras = null): array
    {
        $regras ??= CommissionRule::query()->where('professional_id', $contrato->professional_id)->where('kind', CommissionRule::KIND_INVESTMENT)->get();
        $r = $this->calculator->annualFee($contrato, $this->calculator->holdings($contrato->profile_id), $this->calculator->indexRules($regras));

        return ['base' => $r['base'], 'annual' => $r['annual'], 'per_charge' => $this->calculator->chargeAmount($r['exact'], $contrato->period->months())];
    }

    // --- Regras de investimento --------------------------------------------------------------------------------------

    /** @return Collection<int, CommissionRule> */
    public function rules(User $professional): Collection
    {
        return CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_INVESTMENT)
            ->orderBy('type_key')->orderBy('counterparty')->get();
    }

    public function ruleExists(User $professional, string $escopo, string $alvo, ?string $ignorarId = null): bool
    {
        return CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_INVESTMENT)
            ->where('type_key', $escopo)->where('counterparty_key', AssetFeeCalculator::ruleKey($escopo, $alvo))
            ->when($ignorarId !== null, fn ($q) => $q->where('id', '!=', $ignorarId))->exists();
    }

    /** @param  array{scope: string, target: string, annual_percent: string, notes?: ?string}  $dados */
    public function saveRule(User $professional, ?string $id, array $dados): CommissionRule
    {
        $regra = $id !== null
            ? CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_INVESTMENT)->findOrFail($id)
            : new CommissionRule(['professional_id' => $professional->id, 'kind' => CommissionRule::KIND_INVESTMENT]);

        $alvo = trim($dados['target']);

        $regra->fill([
            'counterparty' => $dados['scope'] === InvestmentRuleScope::AssetClass->value ? ($this->assetClassLabel($alvo)) : $alvo,
            'counterparty_key' => AssetFeeCalculator::ruleKey($dados['scope'], $alvo),
            'type_key' => $dados['scope'],
            'annual_percent' => $dados['annual_percent'],
            'notes' => ($dados['notes'] ?? '') === '' ? null : $dados['notes'],
        ])->save();

        $this->reconcile($professional);

        return $regra;
    }

    public function setRuleActive(User $professional, string $id, bool $ativo): void
    {
        CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_INVESTMENT)->findOrFail($id)->update(['is_active' => $ativo]);
        $this->reconcile($professional);
    }

    public function deleteRule(User $professional, string $id): void
    {
        CommissionRule::query()->ownedBy($professional)->where('kind', CommissionRule::KIND_INVESTMENT)->findOrFail($id)->delete();
        $this->reconcile($professional);
    }

    /**
     * Empresas que aparecem nos investimentos dos clientes ativos do profissional, para sugerir ao criar a regra.
     *
     * @return list<string>
     */
    public function institutionsOf(User $professional): array
    {
        $perfis = array_keys($this->income->clientOptions($professional));

        if ($perfis === []) {
            return [];
        }

        return InvestmentRecord::withoutGlobalScopes()->whereIn('profile_id', $perfis)->where('is_active', true)
            ->whereNotNull('institution')->where('institution', '!=', '')->distinct()->orderBy('institution')->pluck('institution')->all();
    }

    // --- Previsão ------------------------------------------------------------------------------------------------

    /**
     * Refaz a previsão do percentual sobre patrimônio. Idempotente; recebido, cancelado e cobrança de período já
     * encerrado ficam como estão.
     *
     * @return array{criados: int, atualizados: int, removidos: int}
     */
    public function reconcile(User $professional, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $primeiroMes = $hoje->startOfMonth()->toDateString();
        $resultado = ['criados' => 0, 'atualizados' => 0, 'removidos' => 0];
        $regras = $this->calculator->indexRules($this->rules($professional));
        $clientesAtivos = $this->income->clientOptions($professional);

        $contratos = AssetFeeAgreement::query()->where('professional_id', $professional->id)->get();
        $existentes = IncomeEntry::query()->ownedBy($professional)->where('origin_key', 'like', 'asset:%')->get()
            ->groupBy(fn (IncomeEntry $e) => explode(':', (string) $e->origin_key)[1] ?? '');
        $vivos = [];

        foreach ($contratos as $contrato) {
            $vivos[$contrato->id] = true;
            $desejados = [];

            if ($contrato->is_active && array_key_exists($contrato->profile_id, $clientesAtivos)) {
                $r = $this->calculator->annualFee($contrato, $this->calculator->holdings($contrato->profile_id), $regras);
                $valor = $this->calculator->chargeAmount($r['exact'], $contrato->period->months());

                if (bccomp($valor, '0', 2) > 0) {
                    foreach ($this->calculator->periods($contrato, $hoje) as $p) {
                        $chave = "asset:{$contrato->id}:{$p['end']->toDateString()}";
                        $desejados[$chave] = [
                            'due_on' => $p['end']->toDateString(),
                            'amount' => $valor,
                            'description' => "{$contrato->period->label()}: percentual sobre patrimônio de R$ ".number_format((float) $r['base'], 2, ',', '.'),
                        ];
                    }
                }
            }

            $this->apply($professional, $contrato, $existentes->get($contrato->id, collect()), $desejados, $primeiroMes, $hoje, $resultado);
        }

        // Previsão de contrato que não existe mais.
        foreach ($existentes as $contratoId => $entradas) {
            if (! isset($vivos[$contratoId])) {
                $resultado['removidos'] += $this->removeStaleExpected($entradas, $primeiroMes);
            }
        }

        return $resultado;
    }

    /** Rotina diária: refaz a previsão de todo consultor. */
    public function runDailyMaintenance(?CarbonImmutable $hoje = null): array
    {
        $total = ['profissionais' => 0, 'criados' => 0, 'atualizados' => 0, 'removidos' => 0];

        $ids = AssetFeeAgreement::query()->select('professional_id');

        foreach (User::query()->whereIn('id', $ids)->where('role', UserRole::Consultant)->orderBy('id')->get() as $consultor) {
            $total['profissionais']++;

            foreach ($this->reconcile($consultor, $hoje) as $campo => $n) {
                $total[$campo] += $n;
            }
        }

        return $total;
    }

    // --- Internos --------------------------------------------------------------------------------------------------

    private function apply(User $professional, AssetFeeAgreement $contrato, Collection $existentes, array $desejados, string $primeiroMes, CarbonImmutable $hoje, array &$resultado): void
    {
        $porChave = $existentes->keyBy('origin_key');

        foreach ($desejados as $chave => $item) {
            $atual = $porChave->get($chave);

            if ($atual === null) {
                try {
                    IncomeEntry::query()->create([
                        'professional_id' => $professional->id, 'profile_id' => $contrato->profile_id, 'origin_key' => $chave, 'source' => IncomeSource::AssetFee,
                        'status' => IncomeStatus::Expected, 'description' => $item['description'], 'due_on' => $item['due_on'], 'amount' => $item['amount'],
                    ]);
                    $resultado['criados']++;
                } catch (UniqueConstraintViolationException) {
                    // execução paralela já criou
                }

                continue;
            }

            // Período encerrado: o valor deixa de acompanhar o patrimônio de hoje.
            $encerrado = $atual->due_on->lt($hoje);

            if ($atual->status === IncomeStatus::Expected && ! $encerrado
                && ($atual->amount !== $item['amount'] || $atual->description !== $item['description'])) {
                $atual->update(['amount' => $item['amount'], 'description' => $item['description']]);
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

    private function assetClassLabel(string $valor): string
    {
        return \App\Enums\AssetClass::tryFrom($valor)?->label() ?? $valor;
    }
}
