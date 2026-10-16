<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um reajuste da carta e da parcela de um consórcio: o percentual do índice aplicado na data, e os valores antes e depois.
 * Herda dono e privacidade do contrato (copiados na criação).
 */
#[Fillable([
    'profile_id', 'debt_id', 'member_id', 'is_private', 'applied_on', 'percent', 'credit_before', 'credit_after',
    'installment_before', 'installment_after', 'created_by_user_id',
])]
class DebtAdjustment extends Model
{
    use BelongsToProfile, HasUuids, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'applied_on' => 'date',
            'percent' => 'decimal:4',
            'credit_before' => 'decimal:2',
            'credit_after' => 'decimal:2',
            'installment_before' => 'decimal:2',
            'installment_after' => 'decimal:2',
            'is_private' => 'boolean',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
