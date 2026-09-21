<?php

namespace App\Models\Concerns;

use App\Models\Scopes\InsurancePolicyBrokerScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Terceira camada de isolamento, só em InsurancePolicy: além de perfil
 * (BelongsToProfile) e privacidade do casal (RespectsMemberPrivacy), um
 * corretor vinculado só enxerga a apólice que tem o broker_id dele.
 */
trait RespectsBrokerVisibility
{
    public static function bootRespectsBrokerVisibility(): void
    {
        static::addGlobalScope(new InsurancePolicyBrokerScope);
    }

    /**
     * Ignora o filtro de corretor deliberadamente — reservado pra quem já
     * filtra na mão (ver ConsultantPortfolioService::allActivePolicies(),
     * que atravessa vários perfis de propósito e não tem um ProfileContext
     * ativo pra o escopo ler).
     */
    public static function withoutBrokerScope(): Builder
    {
        return static::query()->withoutGlobalScope(InsurancePolicyBrokerScope::class);
    }
}
