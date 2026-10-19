<?php

namespace App\Models;

use App\Enums\BillingPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Honorário fixo que o profissional cobra de um cliente, a cada mês, trimestre, semestre ou ano. Dado DELE (professional_id):
 * não usa BelongsToProfile, ver TenancyCoverageTest::EXEMPT.
 */
#[Fillable(['professional_id', 'profile_id', 'amount', 'period', 'starts_on', 'ends_on', 'is_active', 'notes'])]
class FeeAgreement extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'period' => BillingPeriod::class,
            'amount' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(IncomeEntry::class);
    }
}
