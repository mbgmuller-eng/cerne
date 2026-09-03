<?php

namespace App\Models;

use App\Enums\Necessity;
use App\Models\Concerns\BelongsToProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Quando a descrição contém X, categoria é Y" — aplicada na revisão de um
 * documento importado (ver CategorizationRuleMatcher). `fixed_bill_id`
 * opcional liga a regra a uma conta fixa para casamento/baixa automática.
 *
 * `amount` opcional: quando preenchido, a regra só casa se o valor do item
 * for exatamente igual — caso comum é um PIX recorrente pra si mesmo, cuja
 * descrição sozinha (ex.: "PIX MARCELO") casaria com qualquer PIX daquele
 * nome, não só o de valor fixo.
 */
#[Fillable([
    'profile_id', 'pattern', 'amount', 'category_id', 'subcategory_id', 'necessity',
    'fixed_bill_id', 'is_active',
])]
class ExpenseCategorizationRule extends Model
{
    use BelongsToProfile, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'necessity' => Necessity::class,
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class, 'subcategory_id');
    }

    public function fixedBill(): BelongsTo
    {
        return $this->belongsTo(FixedBill::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
