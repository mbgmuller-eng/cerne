<?php

namespace Database\Factories;

use App\Models\DebtCommission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtCommission>
 */
class DebtCommissionFactory extends Factory
{
    protected $model = DebtCommission::class;

    public function definition(): array
    {
        return [
            'professional_id' => User::factory()->consultant(),
            'role' => DebtCommission::ROLE_SOLD,
            'is_active' => true,
        ];
    }
}
