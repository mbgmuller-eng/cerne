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
        $context = app(ProfileContext::class);
        $profileId = $context->profileId();

        if ($profileId === null) {
            // Falha fechado: sem perfil ativo, nada é visível. O contrário
            // — devolver tudo — vazaria dados de todos os perfis.
            $builder->whereRaw('1 = 0');

            return;
        }

        // Cuidador: LISTA DE PERMITIDOS. Só o model que se declara visível ao cuidador (visibleToCaregiver(), hoje a ficha de saúde e os
        // documentos) devolve linhas; qualquer model de finanças, seguros ou academia, existente ou futuro, devolve zero sem que ninguém precise
        // lembrar. O TenancyCoverageTest/CaregiverIsolationTest trava a lista.
        if ($context->isCaregiver() && ! (method_exists($model, 'visibleToCaregiver') && $model::visibleToCaregiver())) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('profile_id'), $profileId);
    }
}
