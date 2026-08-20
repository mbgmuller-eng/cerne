<?php

namespace App\Models;

use App\Enums\ReserveType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\RespectsMemberPrivacy;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_id', 'member_id', 'reserve_type', 'target_amount',
    'current_amount', 'linked_investment_id',
])]
class FinancialReserve extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected static string $privacyDomain = 'investment_visibility';

    protected function casts(): array
    {
        return [
            'reserve_type' => ReserveType::class,
            'target_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function linkedInvestment(): BelongsTo
    {
        return $this->belongsTo(InvestmentRecord::class, 'linked_investment_id');
    }

    /**
     * Valor efetivo: quando há investimento vinculado, ele é a fonte da
     * verdade — manter dois números manualmente é garantia de divergirem.
     */
    public function effectiveAmount(): string
    {
        return $this->linkedInvestment?->current_amount ?? $this->current_amount;
    }

    public function progressPercentage(): float
    {
        return min(100, Money::percentageOf($this->effectiveAmount(), $this->target_amount));
    }

    public function isComplete(): bool
    {
        return bccomp($this->effectiveAmount(), $this->target_amount, 2) >= 0;
    }
}
