<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Um corretor só vê a apólice que tem o próprio broker_id — decidido
 * apólice por apólice (quem cadastra escolhe, ver InsuranceIndex), não por
 * uma lista de tipos genérica no vínculo consultor↔cliente. Consultor
 * financeiro nunca é restrito por aqui: sempre vê tudo, mesmo raciocínio
 * de MemberPrivacyScope.
 *
 * `$context->isConsultant()` já significa "consultor OU corretor vendo de
 * fora" (ver SetProfileContext) — dentro do bloco é preciso distinguir
 * qual dos dois, porque só o corretor é restrito.
 */
class InsurancePolicyBrokerScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProfileContext::class);

        // Dono, cônjuge, ou nenhum ProfileContext ativo (consulta que
        // atravessa vários perfis de propósito — ex.: painel agregado do
        // corretor filtra na mão, ver ConsultantPortfolioService).
        if (! $context->isConsultant()) {
            return;
        }

        $user = auth()->user();

        // Consultor financeiro: sem restrição, sempre — só corretor tem.
        if ($user === null || ! $user->isBroker()) {
            return;
        }

        $builder->where($model->qualifyColumn('broker_id'), $user->id);
    }
}
