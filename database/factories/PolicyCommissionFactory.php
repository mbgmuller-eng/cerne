<?php

namespace Database\Factories;

use App\Models\InsurancePolicy;
use App\Models\PolicyCommission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PolicyCommission>
 */
class PolicyCommissionFactory extends Factory
{
    protected $model = PolicyCommission::class;

    public function definition(): array
    {
        return [
            'professional_id' => User::factory()->broker(),
            'insurance_policy_id' => InsurancePolicy::factory(),
            'origin' => 'auto',
            'is_active' => true,
        ];
    }
}
