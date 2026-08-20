<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_id', 'description', 'total_amount', 'total_installments',
    'installment_amount', 'first_installment_date', 'credit_card_id', 'created_by_user_id',
])]
class InstallmentGroup extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'total_installments' => 'integer',
            'first_installment_date' => 'date',
        ];
    }

    public function installments(): HasMany
    {
        return $this->hasMany(ExpenseRecord::class)->orderBy('installment_number');
    }

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    /** Parcelas que ainda não venceram — as únicas editáveis. */
    public function pendingInstallments(): HasMany
    {
        return $this->installments()->whereDate('expense_date', '>=', now()->toDateString());
    }

    public function paidCount(): int
    {
        return $this->installments()->whereDate('expense_date', '<', now()->toDateString())->count();
    }

    /** Quanto ainda falta pagar. */
    public function remainingAmount(): string
    {
        return Money::sum(
            $this->installments()
                ->whereDate('expense_date', '>=', now()->toDateString())
                ->pluck('amount')
        );
    }

    public function label(): string
    {
        return $this->description.' · '.$this->total_installments.'x';
    }
}
