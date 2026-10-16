<?php

namespace App\Models;

use App\Enums\DebtInstallmentStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parcela de uma dívida. Quando paga, fica ligada à despesa do fluxo de caixa que a pagou (`expense_id`). Herda dono e
 * privacidade do contrato (copiados na criação e mantidos por Debt::booted()).
 */
#[Fillable([
    'profile_id', 'debt_id', 'member_id', 'is_private', 'number', 'due_date', 'principal_amount', 'interest_amount',
    'fees_amount', 'amount', 'status', 'paid_on', 'paid_amount', 'expense_id',
])]
class DebtInstallment extends Model
{
    use BelongsToProfile, HasUuids, InvalidatesDashboard, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'status' => DebtInstallmentStatus::class,
            'number' => 'integer',
            'due_date' => 'date',
            'paid_on' => 'date',
            'principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'fees_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'is_private' => 'boolean',
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(ExpenseRecord::class, 'expense_id');
    }

    public function isPaid(): bool
    {
        return $this->status === DebtInstallmentStatus::Paid;
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_date->lt(today());
    }
}
