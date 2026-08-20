<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfileOrShared;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['profile_id', 'name', 'icon', 'is_default', 'is_active', 'sort_order'])]
class IncomeCategory extends Model
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

    public function records(): HasMany
    {
        return $this->hasMany(IncomeRecord::class, 'category_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function isDeletable(): bool
    {
        return ! $this->is_default;
    }
}
