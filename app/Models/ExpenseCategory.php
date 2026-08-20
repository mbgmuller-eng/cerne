<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfileOrShared;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria de despesa.
 *
 * Usa BelongsToProfileOrShared em vez do BelongsToProfile comum: com
 * profile_id nulo a categoria é padrão do sistema e precisa ser visível a
 * todos os perfis, mas uma categoria criada por um casal não pode
 * aparecer para outro.
 */
#[Fillable(['profile_id', 'name', 'icon', 'color_hex', 'is_default', 'is_active', 'sort_order'])]
class ExpenseCategory extends Model
{
    use BelongsToProfileOrShared, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(ExpenseSubcategory::class, 'category_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(ExpenseRecord::class, 'category_id');
    }

    /**
     * Categorias utilizáveis, ordenadas para a UI. O isolamento por perfil
     * já vem do escopo global — aqui só filtramos as inativas.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /** Categoria padrão do sistema não pode ser excluída. */
    public function isDeletable(): bool
    {
        return ! $this->is_default;
    }
}
