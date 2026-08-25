<?php

namespace Database\Factories;

use App\Enums\InsuranceType;
use App\Enums\PaymentFrequency;
use App\Models\FinancialProfile;
use App\Models\InsurancePolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsurancePolicy>
 */
class InsurancePolicyFactory extends Factory
{
    protected $model = InsurancePolicy::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'member_id' => null,
            'insurance_type' => InsuranceType::Outro,
            'insurer_name' => fake()->company(),
            'coverage_amount' => fake()->randomFloat(2, 10000, 500000),
            'monthly_premium' => fake()->randomFloat(2, 50, 500),
            'payment_frequency' => PaymentFrequency::Monthly,
            'start_date' => fake()->dateTimeBetween('-2 years', 'now'),
            'is_active' => true,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function life(): static
    {
        return $this->state(fn () => ['insurance_type' => InsuranceType::Vida]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
