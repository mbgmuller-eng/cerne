<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Isolamento das tabelas de taxonomia (categorias e subcategorias).
 *
 * Estas tabelas misturam dois tipos de linha:
 *   - profile_id NULO  = padrão do sistema, compartilhada por todos;
 *   - profile_id X     = criada por um perfil específico.
 *
 * O ProfileScope comum não serve: ele esconderia as padrão. Mas deixar
 * sem escopo nenhum também não serve — uma subcategoria customizada pode
 * se chamar "Terapia da Ana" ou levar o nome de um filho, e vazar isso
 * entre casais é o mesmo problema de privacidade dos lançamentos.
 *
 * Este escopo resolve os dois lados: as padrão sempre passam, as de
 * perfil só passam se forem do perfil ativo.
 */
class SharedTaxonomyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $profileId = app(ProfileContext::class)->profileId();
        $coluna = $model->qualifyColumn('profile_id');

        $builder->where(function (Builder $query) use ($coluna, $profileId): void {
            $query->whereNull($coluna);

            if ($profileId !== null) {
                $query->orWhere($coluna, $profileId);
            }
        });
    }
}
