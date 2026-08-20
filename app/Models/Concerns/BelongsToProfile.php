<?php

namespace App\Models\Concerns;

use App\Models\FinancialProfile;
use App\Models\Scopes\ProfileScope;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolamento multi-tenant.
 *
 * O MySQL não tem Row Level Security como o Postgres do Supabase, então um
 * `where profile_id` esquecido vazaria dados financeiros entre casais. Este
 * escopo global injeta o filtro em TODA query do model — o desenvolvedor não
 * precisa lembrar, e não consegue esquecer.
 *
 * Se o contexto estiver vazio (comando de console, job sem perfil), o escopo
 * devolve zero linhas em vez de todas: falhar fechado é o comportamento certo
 * quando o assunto é dado financeiro alheio.
 *
 * Para sair do escopo deliberadamente — jobs de manutenção, relatórios do
 * admin — use `Model::withoutProfileScope()`, que deixa a intenção explícita
 * na leitura do código.
 */
trait BelongsToProfile
{
    public static function bootBelongsToProfile(): void
    {
        static::addGlobalScope(new ProfileScope);

        // Todo registro nasce carimbado com o perfil ativo.
        static::creating(function (Model $model): void {
            if ($model->getAttribute('profile_id') === null) {
                $model->setAttribute('profile_id', app(ProfileContext::class)->profileId());
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }

    /**
     * Remove o isolamento por perfil. Use apenas em código que
     * legitimamente atravessa perfis (jobs agendados, telas de admin) e
     * deixe claro no chamador por quê.
     */
    public static function withoutProfileScope(): Builder
    {
        return static::query()->withoutGlobalScope(ProfileScope::class);
    }
}
