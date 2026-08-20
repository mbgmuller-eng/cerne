<?php

namespace Database\Factories;

use App\Enums\ConsultantClientStatus;
use App\Models\ConsultantClient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultantClient>
 */
class ConsultantClientFactory extends Factory
{
    protected $model = ConsultantClient::class;

    public function definition(): array
    {
        return [
            'consultant_id' => User::factory()->consultant(),
            'client_id' => User::factory(),
            'status' => ConsultantClientStatus::Active,
            'invited_at' => now(),
            'accepted_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => ConsultantClientStatus::Pending,
            'accepted_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ConsultantClientStatus::Inactive]);
    }
}
