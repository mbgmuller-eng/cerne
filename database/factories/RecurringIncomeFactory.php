<?php

namespace Database\Factories;

use App\Enums\RecurrenceType;
use App\Models\FinancialProfile;
use App\Models\IncomeCategory;
use App\Models\RecurringIncome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringIncome>
 */
class RecurringIncomeFactory extends Factory
{
    protected $model = RecurringIncome::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'member_id' => null,
            'name' => fake()->words(2, true),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'recurrence' => RecurrenceType::Monthly,
            'due_day' => fake()->numberBetween(1, 28),
            'category_id' => IncomeCategory::factory(),
            'is_variable' => false,
            'is_active' => true,
        ];
    }

    public function weekly(int $weekday): static
    {
        return $this->state(fn () => [
            'recurrence' => RecurrenceType::Weekly,
            'due_day' => null,
            'due_weekday' => $weekday,
        ]);
    }

    public function annual(int $month, int $day): static
    {
        return $this->state(fn () => [
            'recurrence' => RecurrenceType::Annual,
            'due_day' => $day,
            'due_month' => $month,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
