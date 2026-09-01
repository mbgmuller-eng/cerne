<?php

namespace Database\Factories;

use App\Enums\Necessity;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCategorizationRule;
use App\Models\FinancialProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategorizationRule>
 */
class ExpenseCategorizationRuleFactory extends Factory
{
    protected $model = ExpenseCategorizationRule::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'pattern' => fake()->unique()->word(),
            'category_id' => ExpenseCategory::factory(),
            'subcategory_id' => null,
            'necessity' => Necessity::Essential,
            'fixed_bill_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
