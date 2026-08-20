<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra toda query pelo perfil ativo. Aplicado automaticamente pelo
 * trait BelongsToProfile — ver a documentação lá.
 */
class ProfileScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $profileId = app(ProfileContext::class)->profileId();

        if ($profileId === null) {
            // Falha fechado: sem perfil ativo, nada é visível. O contrário
            // — devolver tudo — vazaria dados de todos os perfis.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('profile_id'), $profileId);
    }
}
