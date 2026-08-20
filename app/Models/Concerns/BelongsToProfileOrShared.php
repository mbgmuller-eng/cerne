<?php

namespace App\Models\Concerns;

use App\Models\FinancialProfile;
use App\Models\Scopes\SharedTaxonomyScope;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variante do BelongsToProfile para tabelas de taxonomia, onde
 * profile_id nulo significa "padrão do sistema".
 *
 * Ver SharedTaxonomyScope para o porquê de existir um escopo separado.
 */
trait BelongsToProfileOrShared
{
    public static function bootBelongsToProfileOrShared(): void
    {
        static::addGlobalScope(new SharedTaxonomyScope);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }

    /** Só as linhas criadas pelo perfil ativo. */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('profile_id'));
    }

    /** Só as padrão do sistema. */
    public function scopeShared(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('profile_id'));
    }

    public function isShared(): bool
    {
        return $this->profile_id === null;
    }

    /**
     * Ignora o isolamento. Reservado ao seeder da taxonomia e a rotinas de
     * manutenção que legitimamente atravessam perfis.
     */
    public static function withoutTaxonomyScope(): Builder
    {
        return static::query()->withoutGlobalScope(SharedTaxonomyScope::class);
    }
}
