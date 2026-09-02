<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseSubcategory>
 */
class ExpenseSubcategoryFactory extends Factory
{
    protected $model = ExpenseSubcategory::class;

    public function definition(): array
    {
        return [
            // Nula por padrão: subcategoria padrão do sistema, igual ao
            // padrão de ExpenseCategoryFactory.
            'profile_id' => null,
            'category_id' => ExpenseCategory::factory()->shared(),
            'name' => fake()->unique()->word(),
            'is_customizada' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
