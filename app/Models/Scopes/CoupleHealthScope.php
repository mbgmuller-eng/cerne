<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Ficha de saúde: diferente da Academia (PersonalHealthScope, só o dono),
 * aqui o objetivo É a visibilidade entre o casal — alergia, remédio e
 * doença de um precisam aparecer pro outro (é o problema que o recurso
 * resolve: não depender de perguntar). Por isso o escopo filtra só por
 * profile_id, sem checar member_id.
 *
 * O que continua igual à Academia: falha fechado sem perfil ativo, e
 * consultor/corretor NUNCA vê — mesmo vinculado e ativo, mesmo isso
 * sendo o oposto da regra de "consultor vê tudo financeiro". Dado de
 * saúde não é dado financeiro; a exceção do consultor não se aplica aqui.
 */
class CoupleHealthScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProfileContext::class);

        if ($context->isConsultant() || $context->profile() === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('profile_id'), $context->profileId());
    }
}
