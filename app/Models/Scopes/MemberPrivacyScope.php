<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Privacidade do casal, aplicada automaticamente sobre o ProfileScope.
 *
 * O tenancy responde "de qual casal é este dado?"; este escopo responde
 * "dentro do casal, quem pode ver?". É global pelo mesmo motivo do outro:
 * o cônjuge enxergar um gasto marcado como privado é justamente a falha
 * que a funcionalidade existe para impedir, e depender de o desenvolvedor
 * lembrar de filtrar não é garantia nenhuma.
 */
class MemberPrivacyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProfileContext::class);

        // Dono e consultor enxergam tudo — seção 3 e 14 da especificação.
        if (! $context->isRestrictedMember()) {
            return;
        }

        $settings = $context->profile()?->settings();
        $domain = $model::privacyDomain();

        if ($settings === null || $settings->sharesDomain($domain)) {
            return;
        }

        $memberId = $context->memberId();
        $hasJointFlag = $model::hasJointFlag();

        $builder->where(function (Builder $query) use ($model, $memberId, $hasJointFlag): void {
            $query->where($model->qualifyColumn('member_id'), $memberId)
                // member_id nulo = registro da família, visível a todos.
                ->orWhereNull($model->qualifyColumn('member_id'));

            // Conta ou cartão conjunto pertence ao casal, não a um membro:
            // fica visível independentemente da configuração de privacidade.
            if ($hasJointFlag) {
                $query->orWhere($model->qualifyColumn('is_joint'), true);
            }
        });
    }
}
