<?php

namespace Database\Factories;

use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\IncomeRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeRecord>
 */
class IncomeRecordFactory extends Factory
{
    protected $model = IncomeRecord::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'member_id' => null,
            'description' => fake()->words(3, true),
            'category_id' => IncomeCategory::factory(),
            'amount' => fake()->randomFloat(2, 10, 5000),
            'received_date' => fake()->dateTimeBetween('-2 months', 'now'),
            'created_by_user_id' => User::factory(),
        ];
    }
}
