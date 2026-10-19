<?php

namespace Database\Factories;

use App\Enums\AssetFeeMode;
use App\Enums\BillingPeriod;
use App\Models\AssetFeeAgreement;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetFeeAgreement>
 */
class AssetFeeAgreementFactory extends Factory
{
    protected $model = AssetFeeAgreement::class;

    public function definition(): array
    {
        return [
            'professional_id' => User::factory()->consultant(),
            'profile_id' => FinancialProfile::factory(),
            'period' => BillingPeriod::Quarterly,
            'mode' => AssetFeeMode::Total,
            'total_percent' => '1.0000',
            'starts_on' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ];
    }
}
