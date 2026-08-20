<?php

namespace App\Models;

use App\Enums\InvestorType;
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
    'profile_id', 'member_id', 'investor_type',
    'monthly_cost_average', 'months_reserve_target',
])]
class InvestorProfile extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'investor_type' => InvestorType::class,
            'monthly_cost_average' => 'decimal:2',
            'months_reserve_target' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RecommendedAllocation::class);
    }

    /** Meta da reserva de emergência: custo mensal x meses. */
    public function suggestedReserve(): string
    {
        if ($this->monthly_cost_average === null || $this->months_reserve_target === null) {
            return '0.00';
        }

        return Money::parse(
            bcmul($this->monthly_cost_average, (string) $this->months_reserve_target, 2)
        );
    }

    /** A alocação recomendada precisa somar 100%. */
    public function allocationTotal(): string
    {
        return (string) $this->allocations()->sum('target_percentage');
    }

    public function allocationIsValid(): bool
    {
        return bccomp($this->allocationTotal(), '100', 2) === 0;
    }
}
