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
 * "dentro do casal, quem pode ver ESTE lançamento específico?". Cada
 * lançamento decide por si (`is_private`) — não existe mais uma
 * configuração valendo pra tudo de uma vez, e o dono do perfil não é mais
 * um caso especial: a regra vale igual pros dois do casal.
 *
 * Global pelo mesmo motivo do ProfileScope: o cônjuge enxergar um
 * lançamento marcado como privado é justamente a falha que a
 * funcionalidade existe pra impedir, e depender de o desenvolvedor lembrar
 * de filtrar não é garantia nenhuma.
 */
class MemberPrivacyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProfileContext::class);

        // Consultor vinculado enxerga tudo, sempre — seção 14 da especificação.
        if ($context->isConsultant()) {
            return;
        }

        $memberId = $context->memberId();
        $hasJointFlag = $model::hasJointFlag();
        $hasVisibleToPartnerFlag = $model::hasVisibleToPartnerFlag();

        $builder->where(function (Builder $query) use ($model, $memberId, $hasJointFlag, $hasVisibleToPartnerFlag): void {
            $query->where($model->qualifyColumn('member_id'), $memberId)
                // member_id nulo = registro da família, visível a todos.
                ->orWhereNull($model->qualifyColumn('member_id'));

            // Conta/cartão usa o próprio campo (visible_to_partner é o
            // sentido invertido de is_private); os demais usam is_private.
            if ($hasVisibleToPartnerFlag) {
                $query->orWhere($model->qualifyColumn('visible_to_partner'), true);
            } else {
                $query->orWhere($model->qualifyColumn('is_private'), false);
            }

            // Conta ou cartão conjunto pertence ao casal, não a um membro:
            // fica visível independentemente de quem marcou o quê.
            if ($hasJointFlag) {
                $query->orWhere($model->qualifyColumn('is_joint'), true);
            }
        });
    }
}
