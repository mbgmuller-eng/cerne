<?php

namespace Database\Factories;

use App\Models\IncomeCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeCategory>
 */
class IncomeCategoryFactory extends Factory
{
    protected $model = IncomeCategory::class;

    public function definition(): array
    {
        return [
            // Nula por padrão: categoria do sistema, compartilhada — mesma
            // convenção de ExpenseCategoryFactory.
            'profile_id' => null,
            'name' => fake()->unique()->words(2, true),
            'icon' => null,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
