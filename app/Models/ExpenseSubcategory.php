<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfileOrShared;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subcategoria de despesa.
 *
 * O escopo global importa especialmente aqui: uma subcategoria criada
 * pelo usuário pode se chamar "Terapia da Ana" ou levar o nome de um
 * filho. Vazar isso entre casais é o mesmo problema de privacidade dos
 * lançamentos.
 */
#[Fillable(['category_id', 'profile_id', 'name', 'is_customizada', 'is_active', 'sort_order'])]
class ExpenseSubcategory extends Model
{
    use BelongsToProfileOrShared, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'is_customizada' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Cria uma subcategoria sob demanda para o perfil ativo.
     *
     * É o que substitui o "Outros" fixo que a especificação proíbe: em vez
     * de um balaio onde tudo cai e nada se analisa, o usuário nomeia o que
     * está gastando e a categoria passa a existir para ele.
     */
    public static function createCustom(ExpenseCategory $category, string $name): self
    {
        $profileId = app(ProfileContext::class)->profileId();

        return self::firstOrCreate(
            [
                'category_id' => $category->id,
                'profile_id' => $profileId,
                'name' => trim($name),
            ],
            [
                'is_customizada' => true,
                'is_active' => true,
            ],
        );
    }
}
