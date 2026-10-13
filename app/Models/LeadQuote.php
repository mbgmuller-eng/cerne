<?php

namespace App\Models;

use App\Enums\InsuranceType;
use App\Enums\LeadQuoteStatus;
use App\Enums\PaymentFrequency;
use App\Support\LifeCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cotação feita para um lead de seguros: seguradora, prêmio estimado, capital e proteções, validade e a simulação
 * em PDF. Existe antes de a pessoa ser cliente, então não tem perfil: pertence ao lead, e o lead ao profissional.
 */
#[Fillable([
    'lead_id', 'insurance_type', 'insurer_name', 'product', 'reference', 'premium', 'payment_frequency',
    'coverage_amount', 'coverages', 'commission_percent', 'valid_until', 'status', 'notes', 'created_by_user_id',
])]
class LeadQuote extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'insurance_type' => InsuranceType::class,
            'payment_frequency' => PaymentFrequency::class,
            'status' => LeadQuoteStatus::class,
            'premium' => 'decimal:2',
            'coverage_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'coverages' => 'array',
            'valid_until' => 'date',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LeadAttachment::class);
    }

    /** Prêmio de um ano inteiro (quanto o cliente pagaria em 12 meses), para comparar cotações de periodicidades diferentes. */
    public function annualPremium(): ?string
    {
        return $this->premium === null
            ? null
            : bcmul((string) $this->premium, (string) $this->payment_frequency->chargesPerYear(), 2);
    }

    public function monthlyPremium(): ?string
    {
        $anual = $this->annualPremium();

        return $anual === null ? null : bcdiv($anual, '12', 2);
    }

    /** Comissão prevista no primeiro ano: o percentual combinado sobre o prêmio anual. Nulo sem um dos dois. */
    public function expectedCommission(): ?string
    {
        $anual = $this->annualPremium();

        if ($anual === null || $this->commission_percent === null) {
            return null;
        }

        // Meio centavo para cima: bcmul/bcdiv truncam, e a comissão é dinheiro.
        return bcadd(bcdiv(bcmul($anual, (string) $this->commission_percent, 4), '100', 4), '0.005', 2);
    }

    /** Passou da validade sem ter sido aceita nem recusada. */
    public function isExpired(?CarbonImmutable $hoje = null): bool
    {
        return $this->status->isOpen()
            && $this->valid_until !== null
            && $this->valid_until->toDateString() < ($hoje ?? CarbonImmutable::today())->toDateString();
    }

    /** Dias até vencer, só para cotação ainda em aberto e dentro da validade (0 = vence hoje). */
    public function daysToExpire(?CarbonImmutable $hoje = null): ?int
    {
        if (! $this->status->isOpen() || $this->valid_until === null || $this->isExpired($hoje)) {
            return null;
        }

        return (int) ($hoje ?? CarbonImmutable::today())->startOfDay()->diffInDays(CarbonImmutable::instance($this->valid_until)->startOfDay(), false);
    }

    /** Ainda conta como alternativa na mesa: nem recusada, nem vencida. */
    public function isActive(?CarbonImmutable $hoje = null): bool
    {
        return $this->status !== LeadQuoteStatus::Declined && ! $this->isExpired($hoje);
    }

    /**
     * As proteções como aparecem na tela: seguro de vida no padrão do Cerne (mesma lista e ordem das apólices), os
     * demais como foram digitados.
     *
     * @return list<array{name: string, value: ?string, note: ?string}>
     */
    public function displayCoverages(): array
    {
        $lista = $this->coverages ?? [];

        if ($this->insurance_type === InsuranceType::Vida) {
            return LifeCoverage::present($lista);
        }

        return array_values(array_map(fn (array $c) => [
            'name' => (string) ($c['name'] ?? ''),
            'value' => $c['value'] ?? null,
            'note' => ! empty($c['deductible']) ? 'Franquia: '.$c['deductible'] : null,
        ], $lista));
    }
}
