<?php

namespace Database\Factories;

use App\Enums\MemberRole;
use App\Models\FinancialProfile;
use App\Models\ProfileMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfileMember>
 */
class ProfileMemberFactory extends Factory
{
    protected $model = ProfileMember::class;

    public function definition(): array
    {
        return [
            'profile_id' => FinancialProfile::factory(),
            'user_id' => null,
            'name' => fake()->firstName(),
            'role' => MemberRole::Primary,
            'color_hex' => fake()->hexColor(),
            'is_active' => true,
        ];
    }

    public function secondary(): static
    {
        return $this->state(fn () => ['role' => MemberRole::Secondary]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
