<?php

namespace App\Models;

use App\Enums\AssetFeeMode;
use App\Enums\BillingPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Percentual (ao ano) que o profissional cobra do cliente sobre o patrimônio, cobrado a cada mês, trimestre, semestre ou
 * ano. Dado DELE (professional_id): ver TenancyCoverageTest::EXEMPT.
 */
#[Fillable(['professional_id', 'profile_id', 'period', 'mode', 'total_percent', 'starts_on', 'ends_on', 'is_active', 'notes'])]
class AssetFeeAgreement extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'period' => BillingPeriod::class,
            'mode' => AssetFeeMode::class,
            'total_percent' => 'decimal:4',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FinancialProfile::class, 'profile_id');
    }
}
