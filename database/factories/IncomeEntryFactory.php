<?php

namespace Database\Factories;

use App\Enums\IncomeSource;
use App\Enums\IncomeStatus;
use App\Models\IncomeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeEntry>
 */
class IncomeEntryFactory extends Factory
{
    protected $model = IncomeEntry::class;

    public function definition(): array
    {
        return [
            'professional_id' => User::factory()->consultant(),
            'source' => IncomeSource::Other,
            'status' => IncomeStatus::Expected,
            'description' => fake()->words(3, true),
            'due_on' => now()->toDateString(),
            'amount' => '100.00',
        ];
    }

    public function received(?string $amount = null, ?string $on = null): static
    {
        return $this->state(fn (array $a) => [
            'status' => IncomeStatus::Received,
            'received_amount' => $amount ?? $a['amount'],
            'received_on' => $on ?? $a['due_on'],
        ]);
    }
}
