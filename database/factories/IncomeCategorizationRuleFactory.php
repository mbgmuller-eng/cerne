<?php

namespace Database\Factories;

use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeCategorizationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeCategorizationRule>
 */
class IncomeCategorizationRuleFactory extends Factory
{
    protected $model = IncomeCategorizationRule::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'pattern' => fake()->unique()->word(),
            'category_id' => IncomeCategory::factory(),
            'recurring_income_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
