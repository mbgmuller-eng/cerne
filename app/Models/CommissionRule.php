<?php

namespace App\Models;

use App\Enums\CommissionType;
use App\Enums\InsuranceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Quanto uma empresa paga ao profissional (hoje, uma seguradora; depois, administradora de consórcio e empresa de
 * investimento). Dado DELE (professional_id), sigiloso como o resto dos recebimentos: ver TenancyCoverageTest::EXEMPT.
 */
#[Fillable([
    'professional_id', 'kind', 'counterparty', 'counterparty_key', 'insurance_type', 'type_key', 'first_year_percent', 'renewal_percent', 'annual_percent', 'credit_percent', 'installment_schedule',
    'agency_type', 'agency_percent', 'agency_amount', 'payout_delay_days', 'is_active', 'notes',
])]
class CommissionRule extends Model
{
    use HasFactory, HasUuids;

    public const KIND_INSURANCE = 'insurance';

    /** Percentual sobre patrimônio (ao ano) por produto, empresa ou tipo de ativo: `type_key` guarda o escopo (InvestmentRuleScope). */
    public const KIND_INVESTMENT = 'investment';

    /** Comissão de consórcio por administradora: `credit_percent` da carta, paga nas parcelas de `installment_schedule` (ConsortiumSchedule). */
    public const KIND_CONSORTIUM = 'consortium';

    protected function casts(): array
    {
        return [
            'insurance_type' => InsuranceType::class,
            'agency_type' => CommissionType::class,
            'first_year_percent' => 'decimal:3',
            'renewal_percent' => 'decimal:3',
            'annual_percent' => 'decimal:4',
            'credit_percent' => 'decimal:4',
            'agency_percent' => 'decimal:3',
            'agency_amount' => 'decimal:2',
            'payout_delay_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeOwnedBy(Builder $query, User $professional): Builder
    {
        return $query->where('professional_id', $professional->id);
    }

    /** Percentual que vale no ano da apólice: o 1º ano, ou a renovação (que, vazia, repete o 1º ano). */
    public function percentForYear(int $ano): ?string
    {
        if ($ano <= 1) {
            return $this->first_year_percent;
        }

        return $this->renewal_percent ?? $this->first_year_percent;
    }

    public function hasAgency(): bool
    {
        return match ($this->agency_type) {
            CommissionType::Percent => $this->agency_percent !== null && bccomp((string) $this->agency_percent, '0', 3) > 0,
            CommissionType::Amount => $this->agency_amount !== null && bccomp((string) $this->agency_amount, '0', 2) > 0,
            default => false,
        };
    }
}
