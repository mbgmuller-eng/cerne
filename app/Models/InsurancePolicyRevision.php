<?php

namespace App\Models;

use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Enums\PolicyRevisionSource;
use App\Models\Concerns\BelongsToProfile;
use App\Support\LifeCoverage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma versão da apólice: o que valia a partir de `effective_on`. A apólice guarda só o estado de hoje;
 * esta tabela guarda o caminho até aqui (reajustes de prêmio, mudança de capital, renovações).
 *
 * Só se chega a uma versão passando pela apólice (`InsurancePolicy::revisions()`), então privacidade do
 * casal e visibilidade do corretor valem por herança: quem não vê a apólice não vê a linha do tempo.
 */
#[Fillable([
    'profile_id', 'insurance_policy_id', 'effective_on', 'source', 'monthly_premium', 'annual_premium',
    'payment_frequency', 'coverage_amount', 'coverages', 'expiry_date', 'document_id', 'recorded_by_user_id', 'notes',
])]
class InsurancePolicyRevision extends Model
{
    use BelongsToProfile, HasUuids;

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'expiry_date' => 'date',
            'source' => PolicyRevisionSource::class,
            'payment_frequency' => PaymentFrequency::class,
            'monthly_premium' => 'decimal:2',
            'annual_premium' => 'decimal:2',
            'coverage_amount' => 'decimal:2',
            'coverages' => 'array',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'insurance_policy_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** Custo por mês equivalente — comparável entre versões mesmo se a forma de pagamento mudou. */
    public function normalizedMonthlyCost(): string
    {
        return InsurancePolicy::monthlyCostOf($this->payment_frequency, $this->monthly_premium, $this->annual_premium);
    }

    /** O capital desta versão que representa a apólice: "morte qualquer causa" no seguro de vida, o valor segurado nos demais. */
    public function principalCapital(InsuranceType $tipo): ?string
    {
        return $tipo === InsuranceType::Vida
            ? LifeCoverage::deathCapital($this->coverages, $this->coverage_amount)
            : $this->coverage_amount;
    }
}
