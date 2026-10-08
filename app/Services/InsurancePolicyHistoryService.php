<?php

namespace App\Services;

use App\Enums\PaymentFrequency;
use App\Enums\PolicyRevisionSource;
use App\Models\InsurancePolicy;
use App\Models\InsurancePolicyRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Linha do tempo da apólice: cada mudança relevante (prêmio, capital, proteções, forma de pagamento,
 * vencimento) vira uma versão datada. A apólice continua guardando só o estado de hoje.
 *
 * Quem altera a apólice em tela, importação ou renovação passa por `apply()`, que grava a mudança e a
 * versão juntas, e garante que a situação ANTERIOR também esteja na linha do tempo (apólices cadastradas
 * antes de o histórico existir não teriam de onde sair o "de" do primeiro reajuste).
 */
class InsurancePolicyHistoryService
{
    /**
     * Atualiza a apólice e registra a versão.
     *
     * @param  array<string, mixed>  $dados  atributos da apólice a gravar (podem incluir os que não são acompanhados)
     * @param  string|null  $effectiveOn  a partir de quando os valores valem; vazio = hoje
     * @param  bool  $onlyHistory  versão antiga: entra na linha do tempo mas NÃO muda a apólice de hoje
     * @param  bool  $always  registra mesmo se nada mudou (importação de PDF documenta a versão do papel)
     */
    public function apply(
        InsurancePolicy $policy,
        array $dados,
        PolicyRevisionSource $source,
        ?string $effectiveOn = null,
        bool $onlyHistory = false,
        bool $always = false,
        ?string $notes = null,
    ): ?InsurancePolicyRevision {
        $antes = $this->snapshotOf($policy);
        $efetiva = $effectiveOn !== null && $effectiveOn !== '' ? CarbonImmutable::parse($effectiveOn)->toDateString() : now()->toDateString();

        if ($onlyHistory) {
            $this->ensureCurrentRepresented($policy, $antes, $efetiva);

            return $this->record($policy, $efetiva, $this->normalize($this->trackedOnly($dados)), $source, $notes);
        }

        $policy->update($dados);
        $depois = $this->snapshotOf($policy->refresh());
        $mudou = $this->differs($antes, $depois);

        if (! $mudou && ! $always) {
            return null;
        }

        if ($mudou) {
            $this->ensureCurrentRepresented($policy, $antes, $efetiva);
        }

        return $this->record($policy, $efetiva, $depois, $source, $notes);
    }

    /** Apólice recém-criada: a primeira versão vale desde o início da vigência. */
    public function recordCreated(InsurancePolicy $policy, PolicyRevisionSource $source = PolicyRevisionSource::Created, ?string $effectiveOn = null): InsurancePolicyRevision
    {
        return $this->record(
            $policy,
            $effectiveOn !== null && $effectiveOn !== '' ? CarbonImmutable::parse($effectiveOn)->toDateString() : $policy->start_date->toDateString(),
            $this->snapshotOf($policy),
            $source,
            null,
        );
    }

    public function attachDocument(InsurancePolicyRevision $revisao, ?string $documentId): void
    {
        if ($documentId !== null && $revisao->document_id !== $documentId) {
            $revisao->update(['document_id' => $documentId]);
        }
    }

    /** Data da versão mais recente da apólice (para avisar que um PDF é mais antigo que o registrado). */
    public function latestEffectiveOn(InsurancePolicy $policy): ?string
    {
        $data = $this->versoes($policy)->value('effective_on');

        return $data === null ? null : CarbonImmutable::parse($data)->toDateString();
    }

    /**
     * Cada versão com o reajuste em relação à anterior. Recebe as versões da mais recente para a mais antiga
     * (como `InsurancePolicy::revisions()` devolve).
     *
     * @param  Collection<int, InsurancePolicyRevision>  $versoes
     * @return Collection<int, array{versao: InsurancePolicyRevision, premio_pct: ?float, capital_pct: ?float}>
     */
    public function timeline(Collection $versoes): Collection
    {
        $lista = $versoes->values();

        return $lista->map(fn (InsurancePolicyRevision $versao, int $i) => [
            'versao' => $versao,
            'premio_pct' => $versao->premiumChangePercent($lista->get($i + 1)),
            'capital_pct' => $versao->coverageChangePercent($lista->get($i + 1)),
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * Antes de a apólice mudar, a situação de hoje precisa estar na linha do tempo — senão o primeiro
     * reajuste apareceria sem o "de quanto". Entra como "Valores anteriores", datada logo antes da mudança
     * (ou na data em que a apólice entrou no Cerne, se for anterior).
     *
     * @param  array<string, mixed>  $antes
     */
    private function ensureCurrentRepresented(InsurancePolicy $policy, array $antes, string $efetiva): void
    {
        $ultima = $this->versoes($policy)->first();

        if ($ultima !== null && ! $this->differs($this->snapshotOfRevision($ultima), $antes)) {
            return;
        }

        $data = min($policy->created_at->toDateString(), CarbonImmutable::parse($efetiva)->subDay()->toDateString());

        // Não cabe antes da versão mais recente: melhor não registrar do que inventar uma data.
        if ($ultima !== null && $data <= $ultima->effective_on->toDateString()) {
            return;
        }

        $this->record($policy, $data, $antes, PolicyRevisionSource::Baseline, null);
    }

    /**
     * As versões da apólice, da mais recente para a mais antiga, sem depender do perfil ativo: a tela do
     * consultor mexe em apólices de vários clientes sem um contexto único, e a apólice já foi autorizada
     * antes de chegar aqui.
     */
    private function versoes(InsurancePolicy $policy): \Illuminate\Database\Eloquent\Builder
    {
        return InsurancePolicyRevision::withoutGlobalScopes()
            ->where('insurance_policy_id', $policy->id)
            ->orderByDesc('effective_on');
    }

    /** @param  array<string, mixed>  $snapshot já normalizado */
    private function record(InsurancePolicy $policy, string $efetivaEm, array $snapshot, PolicyRevisionSource $source, ?string $notes): InsurancePolicyRevision
    {
        // Atualiza a versão da mesma data em vez de duplicar (índice único por apólice e data); o PDF já
        // ligado a ela é mantido quando esta chamada não traz um novo.
        return InsurancePolicyRevision::withoutGlobalScopes()->updateOrCreate(
            ['insurance_policy_id' => $policy->id, 'effective_on' => $efetivaEm],
            $snapshot + [
                'profile_id' => $policy->profile_id,
                'source' => $source->value,
                'recorded_by_user_id' => auth()->id(),
                'notes' => $notes,
            ],
        );
    }

    /** @return array<string, mixed> */
    public function snapshotOf(InsurancePolicy $policy): array
    {
        return $this->normalize([
            'monthly_premium' => $policy->monthly_premium,
            'annual_premium' => $policy->annual_premium,
            'payment_frequency' => $policy->payment_frequency,
            'coverage_amount' => $policy->coverage_amount,
            'coverages' => $policy->coverages,
            'expiry_date' => $policy->expiry_date,
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshotOfRevision(InsurancePolicyRevision $versao): array
    {
        return $this->normalize([
            'monthly_premium' => $versao->monthly_premium,
            'annual_premium' => $versao->annual_premium,
            'payment_frequency' => $versao->payment_frequency,
            'coverage_amount' => $versao->coverage_amount,
            'coverages' => $versao->coverages,
            'expiry_date' => $versao->expiry_date,
        ]);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function trackedOnly(array $dados): array
    {
        return array_intersect_key($dados, array_flip(['monthly_premium', 'annual_premium', 'payment_frequency', 'coverage_amount', 'coverages', 'expiry_date']));
    }

    /**
     * Forma única de comparar e gravar: decimais com 2 casas, datas ISO, lista de proteções canônica.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function normalize(array $s): array
    {
        $frequencia = $s['payment_frequency'] ?? PaymentFrequency::Monthly;

        return [
            'monthly_premium' => $this->decimal($s['monthly_premium'] ?? '0') ?? '0.00',
            'annual_premium' => $this->decimal($s['annual_premium'] ?? null),
            'payment_frequency' => $frequencia instanceof PaymentFrequency ? $frequencia->value : (string) $frequencia,
            'coverage_amount' => $this->decimal($s['coverage_amount'] ?? null),
            'coverages' => $this->coverages($s['coverages'] ?? null),
            'expiry_date' => ($s['expiry_date'] ?? null) === null || $s['expiry_date'] === ''
                ? null
                : CarbonImmutable::parse($s['expiry_date'])->toDateString(),
        ];
    }

    private function decimal(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : bcadd((string) $valor, '0', 2);
    }

    /**
     * @return list<array{name: string, value: ?string, deductible: ?string}>|null
     */
    private function coverages(mixed $lista): ?array
    {
        if (! is_array($lista) || $lista === []) {
            return null;
        }

        return array_values(array_map(fn (array $c) => [
            'name' => trim((string) ($c['name'] ?? '')),
            'value' => $this->decimal($c['value'] ?? null),
            'deductible' => isset($c['deductible']) && trim((string) $c['deductible']) !== '' ? trim((string) $c['deductible']) : null,
        ], $lista));
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function differs(array $a, array $b): bool
    {
        return json_encode($a) !== json_encode($b);
    }
}
