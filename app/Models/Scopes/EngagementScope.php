<?php

namespace App\Models\Scopes;

use App\Models\EngagementNote;
use App\Services\EngagementAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Acompanhamento só é visível a quem tem acesso à relação de consultoria (ver EngagementAccess), e só a registros da relação
 * dele: o cliente não vê nada de um consultor com o vínculo encerrado, e um consultor novo não herda o histórico do anterior.
 * Falha fechado, como o escopo de perfil: sem visitante, nada aparece.
 *
 * Nota privada é do consultor que a escreveu: o cliente nunca a enxerga.
 */
class EngagementScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $visitante = app(EngagementAccess::class)->viewer();

        if ($visitante === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->qualifyColumn('consultant_id'), $visitante->consultantIds);

        if ($model instanceof EngagementNote) {
            $builder->where(function (Builder $q) use ($model, $visitante): void {
                $q->where($model->qualifyColumn('is_private'), false);

                if ($visitante->isConsultant()) {
                    $q->orWhere(fn (Builder $privada) => $privada
                        ->where($model->qualifyColumn('is_private'), true)
                        ->where($model->qualifyColumn('author_user_id'), $visitante->userId));
                }
            });
        }
    }
}
