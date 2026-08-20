<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\BankAccount;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'member_id' => ProfileMember::factory(),
            'bank_name' => fake()->randomElement(['Nubank', 'Itaú', 'Bradesco', 'Inter', 'Santander']),
            'account_type' => AccountType::Checking,
            'agency' => fake()->numerify('####'),
            'account_number' => fake()->numerify('#####-#'),
            'current_balance' => fake()->randomFloat(2, 100, 20000),
            'is_joint' => false,
            'visible_to_partner' => true,
            'included_in_consolidated' => true,
            'is_active' => true,
        ];
    }

    public function joint(): static
    {
        return $this->state(fn () => ['is_joint' => true]);
    }

    public function private(): static
    {
        return $this->state(fn () => [
            'is_joint' => false,
            'visible_to_partner' => false,
            'included_in_consolidated' => false,
        ]);
    }
}
