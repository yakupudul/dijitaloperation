<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\LeadOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadOutcome>
 */
class LeadOutcomeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'lead_source' => 'meta_lead_form',
            'lead_ref' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'lead_received_at' => now()->subDays(fake()->numberBetween(1, 20)),
            'campaign_label' => 'Form – '.fake()->word(),
            'contact_hint' => null,
            'status' => LeadOutcome::STATUS_NEW,
        ];
    }

    public function outcome(string $status, ?float $value = null): static
    {
        return $this->state(fn (): array => ['status' => $status, 'value_try' => $value, 'marked_at' => now()]);
    }
}
