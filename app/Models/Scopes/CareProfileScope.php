<?php

namespace App\Models\Scopes;

use App\Enums\ProfileType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Esconde o perfil de cuidado (pessoa acompanhada) de TODA consulta de perfis que não o peça de propósito.
 *
 * O código antigo acha o perfil de um cliente por `owner_user_id` (carteira do consultor, agenda, honorários, datas importantes, vínculo,
 * perfil padrão ao entrar...). Sem este escopo, quem é dono de um perfil de cuidado teria a mãe aparecendo como "cliente" para o
 * consultor dele, com nome e data de nascimento. Aqui o padrão é falhar fechado: perfil de cuidado só aparece por
 * `FinancialProfile::withCareProfiles()`, e quem chama isso sabe o que está fazendo.
 */
class CareProfileScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('profile_type'), '!=', ProfileType::Care->value);
    }
}
