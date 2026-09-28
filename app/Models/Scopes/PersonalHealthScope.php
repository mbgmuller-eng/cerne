<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Dado de saúde pessoal (treino): só o próprio membro enxerga.
 *
 * É o oposto de MemberPrivacyScope. Lá o consultor vinculado vê tudo e o
 * cônjuge vê o que não foi marcado como privado; aqui NINGUÉM além do dono
 * vê — nem o cônjuge, nem consultor, nem corretor.
 *
 * Falha fechado: sem membro no contexto (profissional operando o perfil do
 * cliente, requisição sem perfil, qualquer caso não previsto) devolve zero
 * linhas em vez de "tudo do perfil". Consultor é barrado mesmo que o
 * contexto, por algum motivo, carregue um membro.
 */
class PersonalHealthScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProfileContext::class);

        if ($context->isConsultant() || $context->memberId() === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('member_id'), $context->memberId());
    }
}
