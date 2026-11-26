<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Quem vê um profissional é quem enxerga pelo menos uma das pessoas que ele atende. A conta é feita pela ligação, que já passa pelo CoupleHealthScope:
 * o casal vê as dos dois, quem acompanha uma pessoa vê só as dela, consultor e corretor não veem nada. Sem perfil ativo, fecha.
 */
class HealthProfessionalScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProfileContext::class);

        if ($context->isConsultant() || $context->profile() === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('profile_id'), $context->profileId())->whereHas('links');
    }
}
