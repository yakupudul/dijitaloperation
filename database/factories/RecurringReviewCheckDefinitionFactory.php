<?php

namespace Database\Factories;

use App\Models\RecurringReviewCheckDefinition;
use App\Models\RecurringReviewSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringReviewCheckDefinition>
 */
class RecurringReviewCheckDefinitionFactory extends Factory
{
    protected $model = RecurringReviewCheckDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => RecurringReviewSchedule::factory(),
            'position' => 1,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'is_required' => true,
            'is_active' => true,
            'finding_rule_stable_id' => null,
            'opportunity_rule_stable_id' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
