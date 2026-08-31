<?php

namespace App\Models;

use App\Enums\FundingMethod;
use App\Enums\GoalStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\RespectsMemberPrivacy;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_id', 'member_id', 'name', 'priority', 'estimated_value', 'target_date',
    'funding_method', 'installment_amount', 'current_amount', 'linked_investment_id',
    'status', 'notes', 'created_by_user_id', 'is_private',
])]
class Goal extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'funding_method' => FundingMethod::class,
            'status' => GoalStatus::class,
            'priority' => 'integer',
            'estimated_value' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'target_date' => 'date',
            'is_private' => 'boolean',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', GoalStatus::Active);
    }

    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority')->orderBy('target_date');
    }

    /**
     * Valor acumulado. Havendo investimento vinculado, ele é a fonte da
     * verdade — manter dois números à mão é garantia de divergirem.
     */
    public function accumulated(): string
    {
        return $this->linkedInvestment?->current_amount ?? $this->current_amount;
    }

    public function progressPercentage(): float
    {
        return min(100, Money::percentageOf($this->accumulated(), $this->estimated_value));
    }

    public function remaining(): string
    {
        $falta = bcsub($this->estimated_value, $this->accumulated(), 2);

        return bccomp($falta, '0', 2) > 0 ? $falta : '0.00';
    }

    /**
     * Quanto guardar por mês para chegar na data-alvo.
     *
     * Devolve null quando não há data ou o prazo já passou — nesses casos
     * não existe parcela mensal a sugerir, e inventar um número seria
     * pior do que não mostrar nada.
     */
    public function monthlyNeeded(): ?string
    {
        if ($this->target_date === null || $this->target_date->isPast()) {
            return null;
        }

        $meses = max(1, (int) ceil(now()->diffInMonths($this->target_date)));

        return Money::parse(bcdiv($this->remaining(), (string) $meses, 2));
    }

    public function isAchieved(): bool
    {
        return $this->status === GoalStatus::Achieved
            || bccomp($this->accumulated(), $this->estimated_value, 2) >= 0;
    }
}
