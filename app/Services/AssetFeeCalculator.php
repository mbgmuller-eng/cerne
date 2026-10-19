<?php

namespace App\Services;

use App\Enums\AssetClass;
use App\Enums\AssetFeeMode;
use App\Enums\InvestmentRuleScope;
use App\Models\AssetFeeAgreement;
use App\Models\CommissionRule;
use App\Models\Insurer;
use App\Models\InvestmentRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Conta do percentual sobre patrimônio, sem gravar nada.
 *
 * O percentual é ao ano; cada cobrança vale `percentual × meses do período ÷ 12` sobre o patrimônio. No modo "todo o
 * patrimônio" é um percentual só; no modo "regras" cada produto paga o percentual da sua regra (produto > empresa > tipo
 * de ativo) e o que não tem regra fica fora da base. Consórcio não entra: antes da contemplação não é patrimônio.
 *
 * O patrimônio usado é o de hoje (`current_amount` dos investimentos ativos): serve para estimar as cobranças que ainda
 * vão acontecer. Somas por grupo em SQL; o percentual é aplicado por grupo em bcmath.
 */
class AssetFeeCalculator
{
    /** Quanto à frente se enxerga. */
    public const HORIZON_MONTHS = 12;

    /**
     * Patrimônio hoje, agrupado por produto, empresa e tipo de ativo.
     *
     * @return Collection<int, array{asset_class: ?string, institution: ?string, ticker: ?string, name: ?string, total: string}>
     */
    public function holdings(string $profileId): Collection
    {
        return InvestmentRecord::withoutGlobalScopes()
            ->where('profile_id', $profileId)->where('is_active', true)
            ->where('asset_class', '!=', AssetClass::Consorcio->value)
            ->selectRaw('asset_class, institution, ticker, name, SUM(current_amount) AS total')
            ->groupBy('asset_class', 'institution', 'ticker', 'name')
            ->get()
            ->map(fn ($g) => [
                'asset_class' => $g->asset_class?->value,
                'institution' => $g->institution,
                'ticker' => $g->ticker,
                'name' => $g->name,
                'total' => (string) $g->total,
            ]);
    }

    /**
     * @param  array<string, CommissionRule>  $regras  ver indexRules()
     * @return array{base: string, annual: string, exact: string}  patrimônio que paga, quanto paga por ano (2 casas, para mostrar) e o mesmo sem arredondar (para dividir em cobranças)
     */
    public function annualFee(AssetFeeAgreement $agreement, Collection $holdings, array $regras): array
    {
        $base = '0';
        $anual = '0';

        foreach ($holdings as $g) {
            $percentual = $agreement->mode === AssetFeeMode::Total
                ? ($agreement->total_percent !== null ? (string) $agreement->total_percent : null)
                : $this->percentFor($regras, $g);

            if ($percentual === null || bccomp($g['total'], '0', 2) <= 0) {
                continue;
            }

            $base = bcadd($base, $g['total'], 2);
            $anual = bcadd($anual, bcdiv(bcmul($g['total'], $percentual, 8), '100', 8), 8);
        }

        return ['base' => $base, 'annual' => bcadd($anual, '0.005', 2), 'exact' => $anual];
    }

    /** Valor de uma cobrança: o ano inteiro dividido pelo número de meses do período. */
    public function chargeAmount(string $annual, int $months): string
    {
        return bcadd(bcdiv(bcmul($annual, (string) $months, 6), '12', 6), '0.005', 2);
    }

    /**
     * Os períodos de cobrança que terminam do mês atual em diante. Cada período começa na data de início do contrato e a
     * cobrança é feita no último dia dele; período que o contrato não completa (término antes do fim) não é cobrado.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function periods(AssetFeeAgreement $agreement, ?CarbonImmutable $hoje = null): array
    {
        $hoje ??= CarbonImmutable::today();
        $primeiroMes = $hoje->startOfMonth();
        $limite = $hoje->addMonthsNoOverflow(self::HORIZON_MONTHS)->endOfMonth()->startOfDay();
        $passo = $agreement->period->months();
        $inicio = CarbonImmutable::parse($agreement->starts_on);
        $fimContrato = $agreement->ends_on !== null ? CarbonImmutable::parse($agreement->ends_on) : null;
        $periodos = [];

        for ($k = 0; ; $k++) {
            $comeco = $inicio->addMonthsNoOverflow($k * $passo);
            $fim = $inicio->addMonthsNoOverflow(($k + 1) * $passo)->subDay();

            if ($comeco->gt($limite) || ($fimContrato !== null && $fim->gt($fimContrato))) {
                break;
            }

            if ($fim->lt($primeiroMes)) {
                continue;
            }

            $periodos[] = ['start' => $comeco, 'end' => $fim];
        }

        return $periodos;
    }

    /**
     * Regras de investimento do profissional, indexadas "escopo|chave" para achar a mais específica.
     *
     * @param  Collection<int, CommissionRule>  $regras
     * @return array<string, CommissionRule>
     */
    public function indexRules(Collection $regras): array
    {
        return $regras->where('is_active', true)->where('annual_percent', '!==', null)
            ->mapWithKeys(fn (CommissionRule $r) => [$r->type_key.'|'.$r->counterparty_key => $r])->all();
    }

    /** Chave de uma regra: tipo de ativo guarda o valor do enum; produto e empresa guardam o nome sem acento nem maiúscula. */
    public static function ruleKey(string $escopo, string $alvo): string
    {
        return $escopo === InvestmentRuleScope::AssetClass->value ? $alvo : Insurer::normalizeName($alvo);
    }

    /** @param  array<string, CommissionRule>  $regras */
    private function percentFor(array $regras, array $g): ?string
    {
        $candidatos = [];

        foreach ([$g['ticker'], $g['name']] as $produto) {
            if (filled($produto)) {
                $candidatos[] = InvestmentRuleScope::Product->value.'|'.self::ruleKey('product', $produto);
            }
        }

        if (filled($g['institution'])) {
            $candidatos[] = InvestmentRuleScope::Institution->value.'|'.self::ruleKey('institution', $g['institution']);
        }

        if ($g['asset_class'] !== null) {
            $candidatos[] = InvestmentRuleScope::AssetClass->value.'|'.$g['asset_class'];
        }

        foreach ($candidatos as $chave) {
            if (isset($regras[$chave])) {
                return (string) $regras[$chave]->annual_percent;
            }
        }

        return null;
    }
}
