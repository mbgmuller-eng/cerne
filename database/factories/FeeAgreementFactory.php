<?php

namespace Database\Factories;

use App\Enums\BillingPeriod;
use App\Models\FeeAgreement;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeAgreement>
 */
class FeeAgreementFactory extends Factory
{
    protected $model = FeeAgreement::class;

    public function definition(): array
    {
        return [
            'professional_id' => User::factory()->consultant(),
            'profile_id' => FinancialProfile::factory(),
            'amount' => '500.00',
            'period' => BillingPeriod::Monthly,
            'starts_on' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ];
    }
}
