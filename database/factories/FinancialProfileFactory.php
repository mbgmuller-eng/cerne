<?php

namespace Database\Factories;

use App\Enums\ProfileType;
use App\Models\FinancialProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialProfile>
 */
class FinancialProfileFactory extends Factory
{
    protected $model = FinancialProfile::class;

    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'profile_name' => 'Família '.fake()->lastName(),
            'profile_type' => ProfileType::Single,
            'base_currency' => 'BRL',
            'reference_month' => 1,
        ];
    }

    public function couple(): static
    {
        return $this->state(fn () => ['profile_type' => ProfileType::Couple]);
    }

    public function family(): static
    {
        return $this->state(fn () => ['profile_type' => ProfileType::Family]);
    }
}
