<?php

namespace App\Models\Scopes;

use App\Models\HealthCard;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Peso e medidas: mais fechados que o resto da Ficha de Saúde. Cada pessoa vê as suas. O cônjuge e quem acompanha a saúde dela só veem se ELA
 * ligou o compartilhamento. No espaço de uma pessoa acompanhada, quem cuida vê (é para isso que o espaço existe). Consultor e corretor, nunca.
 */
class HealthMeasurementScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $contexto = app(ProfileContext::class);

        if ($contexto->isConsultant() || $contexto->profile() === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('profile_id'), $contexto->profileId());

        if ($contexto->profile()->isCare()) {
            if ($contexto->isCaregiver()) {
                $builder->whereIn($model->qualifyColumn('member_id'), $contexto->grantedMemberIds());
            }

            return;
        }

        $compartilharam = HealthCard::query()->withoutGlobalScopes()
            ->where('profile_id', $contexto->profileId())->where('share_measurements', true)->pluck('member_id')->all();

        if ($contexto->isCaregiver()) {
            $builder->whereIn($model->qualifyColumn('member_id'), array_values(array_intersect($compartilharam, $contexto->grantedMemberIds())));

            return;
        }

        $meu = $contexto->memberId();

        if ($meu === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->qualifyColumn('member_id'), [...$compartilharam, $meu]);
    }
}
