<?php

namespace Database\Factories;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'consultant_id' => User::factory()->consultant(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->numerify('###########'),
            'stage' => LeadStage::NewContact,
        ];
    }

    public function stage(LeadStage $stage): static
    {
        return $this->state(fn () => ['stage' => $stage]);
    }
}
