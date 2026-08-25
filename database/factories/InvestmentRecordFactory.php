<?php

namespace Database\Factories;

use App\Enums\AssetClass;
use App\Enums\InvestmentSector;
use App\Models\FinancialProfile;
use App\Models\InvestmentRecord;
use App\Models\ProfileMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestmentRecord>
 */
class InvestmentRecordFactory extends Factory
{
    protected $model = InvestmentRecord::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'member_id' => ProfileMember::factory(),
            'sector' => InvestmentSector::FixedIncome,
            'asset_class' => AssetClass::Cdb,
            'name' => fake()->words(2, true),
            'institution' => fake()->randomElement(['XP Investimentos', 'Itaú', 'Nubank', 'BTG Pactual']),
            'current_amount' => fake()->randomFloat(2, 1000, 50000),
            'invested_amount' => fake()->randomFloat(2, 1000, 50000),
            'is_active' => true,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
