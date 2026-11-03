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

        // Quem acompanha: LISTA DE PERMITIDOS, uma para cada coisa que a pessoa pode ter liberado. Saúde (visibleToCaregiver(): a ficha, a agenda e
        // os documentos) só com acesso à saúde; finanças (visibleToFamily()) só com acesso às finanças. O que não se declara, existente ou futuro,
        // devolve zero sem que ninguém precise lembrar. O CaregiverTest trava as duas listas.
        if ($context->isCaregiver()) {
            $saude = $context->hasHealthAccess() && method_exists($model, 'visibleToCaregiver') && $model::visibleToCaregiver();
            $familia = $context->hasFinanceAccess() && method_exists($model, 'visibleToFamily') && $model::visibleToFamily();

            if (! $saude && ! $familia) {
                $builder->whereRaw('1 = 0');

                return;
            }
        }

        $builder->where($model->qualifyColumn('profile_id'), $profileId);
    }
}
