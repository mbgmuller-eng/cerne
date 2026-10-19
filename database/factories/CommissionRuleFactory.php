<?php

namespace Database\Factories;

use App\Models\CommissionRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionRule>
 */
class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    public function definition(): array
    {
        return [
            'professional_id' => User::factory()->broker(),
            'kind' => CommissionRule::KIND_INSURANCE,
            'counterparty' => 'Porto Seguro',
            'counterparty_key' => 'porto seguro',
            'type_key' => '*',
            'first_year_percent' => '30.000',
            'payout_delay_days' => 0,
            'is_active' => true,
        ];
    }
}
