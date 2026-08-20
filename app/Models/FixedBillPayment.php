<?php

namespace App\Models;

use App\Enums\FixedBillPaymentStatus;
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
 * O vencimento concreto de uma conta fixa num mês.
 *
 * Não usa RespectsMemberPrivacy: herda a privacidade da conta que o
 * originou, e as telas sempre chegam aqui a partir dela.
 */
#[Fillable([
    'profile_id', 'fixed_bill_id', 'year', 'month', 'due_date',
    'amount_paid', 'status', 'paid_at', 'notes',
])]
class FixedBillPayment extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'due_date' => 'date',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'date',
            'status' => FixedBillPaymentStatus::class,
        ];
    }

    public function fixedBill(): BelongsTo
    {
        return $this->belongsTo(FixedBill::class);
    }

    /** Ainda pesa no mês: pendente ou vencida. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FixedBillPaymentStatus::Pending,
            FixedBillPaymentStatus::Overdue,
        ]);
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('year', $year)->where('month', $month);
    }

    /** Vence nos próximos N dias — alimenta os alertas da Visão Geral. */
    public function scopeDueWithin(Builder $query, int $dias): Builder
    {
        return $query->outstanding()
            ->whereDate('due_date', '>=', now()->toDateString())
            ->whereDate('due_date', '<=', now()->addDays($dias)->toDateString());
    }

    /**
     * Valor a considerar: o pago, se houver; senão o previsto na conta.
     * Conta variável sem pagamento é só estimativa.
     */
    public function effectiveAmount(): string
    {
        return $this->amount_paid ?? $this->fixedBill->amount;
    }

    public function isPaid(): bool
    {
        return $this->status === FixedBillPaymentStatus::Paid;
    }
}
