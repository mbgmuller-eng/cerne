<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\RespectsMemberPrivacy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_id', 'member_id', 'name', 'amount', 'due_day', 'bank_account_id',
    'credit_card_id', 'category_id', 'subcategory_id', 'is_variable', 'is_active', 'notes',
])]
class FixedBill extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids, RespectsMemberPrivacy;

    // Conta fixa é despesa: segue a mesma visibilidade das despesas.
    protected static string $privacyDomain = 'expense_visibility';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_day' => 'integer',
            'is_variable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FixedBillPayment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Data de vencimento numa competência.
     *
     * Conta que vence dia 31 não tem dia 31 em fevereiro — cai no último
     * dia do mês, mesma regra do fechamento de cartão.
     */
    public function dueDateFor(int $year, int $month): CarbonImmutable
    {
        $primeiro = CarbonImmutable::create($year, $month, 1);

        return $primeiro->setDay(min($this->due_day, $primeiro->daysInMonth))->startOfDay();
    }
}
