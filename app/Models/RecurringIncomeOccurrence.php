<?php

namespace App\Models;

use App\Enums\RecurringIncomeStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O recebimento concreto de uma receita recorrente numa data — espelho de
 * FixedBillPayment.
 *
 * Não usa RespectsMemberPrivacy: herda a privacidade da receita que a
 * originou, mesma razão de FixedBillPayment.
 */
#[Fillable([
    'profile_id', 'recurring_income_id', 'year', 'month', 'due_date',
    'amount_received', 'status', 'received_at', 'notes',
])]
class RecurringIncomeOccurrence extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'due_date' => 'date',
            'amount_received' => 'decimal:2',
            'received_at' => 'date',
            'status' => RecurringIncomeStatus::class,
        ];
    }

    public function recurringIncome(): BelongsTo
    {
        return $this->belongsTo(RecurringIncome::class);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            RecurringIncomeStatus::Pending,
            RecurringIncomeStatus::Overdue,
        ]);
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /** Valor a considerar: o recebido, se houver; senão o previsto. */
    public function effectiveAmount(): string
    {
        return $this->amount_received ?? $this->recurringIncome->amount;
    }

    public function isReceived(): bool
    {
        return $this->status === RecurringIncomeStatus::Received;
    }
}
