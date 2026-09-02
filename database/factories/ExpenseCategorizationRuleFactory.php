<?php

namespace Database\Factories;

use App\Enums\Necessity;
use App\Models\ExpenseCategory;
use App\Models\ExpenseCategorizationRule;
use App\Models\ExpenseSubcategory;
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
            // Obrigatória pra necessidade que não é Investimento — o
            // padrão do factory já vem preenchida, vinculada à MESMA
            // categoria acima (mesmo raciocínio de ExpenseRecordFactory).
            'subcategory_id' => function (array $attributes) {
                return ExpenseSubcategory::factory()->create(['category_id' => $attributes['category_id']])->id;
            },
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
