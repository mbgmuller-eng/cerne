<?php

namespace Database\Factories;

use App\Models\ExpenseCategory;
use App\Models\FinancialProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    public function definition(): array
    {
        return [
            // Nula por padrão: categoria do sistema, compartilhada por
            // todos os perfis. Use custom() para uma categoria de um perfil.
            'profile_id' => null,
            'name' => fake()->unique()->words(2, true),
            'icon' => null,
            'color_hex' => fake()->hexColor(),
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn () => ['profile_id' => null, 'is_default' => true]);
    }

    public function custom(?FinancialProfile $profile = null): static
    {
        return $this->state(fn () => [
            'profile_id' => $profile?->id ?? FinancialProfile::factory(),
        ]);
    }
}
