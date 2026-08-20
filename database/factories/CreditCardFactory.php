<?php

namespace Database\Factories;

use App\Enums\CardBrand;
use App\Models\CreditCard;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
{
    protected $model = CreditCard::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'member_id' => ProfileMember::factory(),
            'card_name' => fake()->randomElement(['Nubank Roxinho', 'Itaú Click', 'Inter Gold']),
            'bank_name' => fake()->randomElement(['Nubank', 'Itaú', 'Inter']),
            'card_brand' => CardBrand::Mastercard,
            'credit_limit' => fake()->randomFloat(2, 1000, 30000),
            'closing_day' => 20,
            'due_day' => 28,
            'last_four_digits' => fake()->numerify('####'),
            'is_joint' => false,
            'visible_to_partner' => true,
            'included_in_consolidated' => true,
            'is_active' => true,
        ];
    }

    /** Cartão que fecha no último dia — o caso de borda de fevereiro. */
    public function closingOnLastDay(): static
    {
        return $this->state(fn () => ['closing_day' => 31, 'due_day' => 10]);
    }

    public function joint(): static
    {
        return $this->state(fn () => ['is_joint' => true]);
    }
}
