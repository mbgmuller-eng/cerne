<?php

namespace Database\Factories;

use App\Enums\InsuranceType;
use App\Enums\LeadQuoteStatus;
use App\Enums\PaymentFrequency;
use App\Models\Lead;
use App\Models\LeadQuote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadQuote>
 */
class LeadQuoteFactory extends Factory
{
    protected $model = LeadQuote::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory()->insurance(),
            'insurance_type' => InsuranceType::Vida,
            'insurer_name' => fake()->randomElement(['Azos', 'Icatu', 'Porto Seguro', 'Bradesco Seguros']),
            'premium' => fake()->randomFloat(2, 50, 600),
            'payment_frequency' => PaymentFrequency::Monthly,
            'status' => LeadQuoteStatus::Draft,
        ];
    }

    public function status(LeadQuoteStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
