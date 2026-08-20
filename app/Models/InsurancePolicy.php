<?php

namespace App\Models;

use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
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
    'profile_id', 'member_id', 'insurance_type', 'insurer_name', 'policy_number',
    'coverage_amount', 'monthly_premium', 'annual_premium', 'payment_frequency',
    'bank_account_id', 'start_date', 'expiry_date', 'is_active', 'beneficiaries',
    'notes', 'source_document_id', 'created_by_user_id',
])]
class InsurancePolicy extends Model
{
    use Auditable, BelongsToProfile, InvalidatesDashboard, HasFactory, HasUuids, RespectsMemberPrivacy;

    protected static string $privacyDomain = 'insurance_visibility';

    protected function casts(): array
    {
        return [
            'insurance_type' => InsuranceType::class,
            'payment_frequency' => PaymentFrequency::class,
            'coverage_amount' => 'decimal:2',
            'monthly_premium' => 'decimal:2',
            'annual_premium' => 'decimal:2',
            'start_date' => 'date',
            'expiry_date' => 'date',
            'is_active' => 'boolean',
            // Funciona igual em MySQL e MariaDB; a diferença entre os dois
            // só apareceria se consultássemos JSON pelo banco.
            'beneficiaries' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Custo mensal equivalente.
     *
     * Apólice anual custa o mesmo no ano, mas comparar R$ 2.400/ano com
     * R$ 180/mês exige normalizar — senão o resumo soma laranjas com maçãs.
     */
    public function normalizedMonthlyCost(): string
    {
        if ($this->payment_frequency === PaymentFrequency::Monthly) {
            return $this->monthly_premium;
        }

        $anual = $this->annual_premium
            ?? bcmul($this->monthly_premium, (string) $this->payment_frequency->chargesPerYear(), 2);

        return Money::parse(bcdiv($anual, '12', 2));
    }

    /** @return array<int, array{name: string, percentage: float}> */
    public function beneficiaryList(): array
    {
        return $this->beneficiaries ?? [];
    }

    /** Os percentuais dos beneficiários precisam somar 100. */
    public function beneficiariesAreValid(): bool
    {
        $lista = $this->beneficiaryList();

        if ($lista === []) {
            return true;
        }

        $soma = array_sum(array_column($lista, 'percentage'));

        return abs($soma - 100) < 0.01;
    }

    public function isExpiring(int $dias = 30): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->isBetween(now(), now()->addDays($dias));
    }
}
