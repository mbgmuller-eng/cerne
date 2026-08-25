<?php

namespace Database\Factories;

use App\Enums\Necessity;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseRecord>
 */
class ExpenseRecordFactory extends Factory
{
    protected $model = ExpenseRecord::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'member_id' => null,
            'description' => fake()->words(3, true),
            'necessity' => Necessity::Discretionary,
            'category_id' => ExpenseCategory::factory()->shared(),
            'amount' => fake()->randomFloat(2, 10, 1000),
            'expense_date' => fake()->dateTimeBetween('-2 months', 'now'),
            'created_by_user_id' => User::factory(),
        ];
    }
}
