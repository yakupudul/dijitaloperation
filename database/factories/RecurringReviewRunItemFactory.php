<?php

namespace Database\Factories;

use App\Enums\RecurringReviewRunItemState;
use App\Models\RecurringReviewCheckDefinition;
use App\Models\RecurringReviewRun;
use App\Models\RecurringReviewRunItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringReviewRunItem>
 */
class RecurringReviewRunItemFactory extends Factory
{
    protected $model = RecurringReviewRunItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => RecurringReviewRun::factory(),
            'check_definition_id' => function (array $attributes): int {
                if (isset($attributes['run_id'])) {
                    $scheduleId = RecurringReviewRun::query()
                        ->whereKey($attributes['run_id'])
                        ->value('schedule_id');
                    if ($scheduleId !== null) {
                        return RecurringReviewCheckDefinition::factory()->create([
                            'schedule_id' => $scheduleId,
                        ])->id;
                    }
                }

                return RecurringReviewCheckDefinition::factory()->create()->id;
            },
            'position' => 1,
            'title_snapshot' => fake()->sentence(4),
            'description_snapshot' => fake()->optional()->sentence(),
            'is_required_snapshot' => true,
            'finding_rule_stable_id_snapshot' => null,
            'opportunity_rule_stable_id_snapshot' => null,
            'state' => RecurringReviewRunItemState::Pending->value,
            'outcome_kind' => null,
            'evidence_id' => null,
            'finding_id' => null,
            'opportunity_id' => null,
            'task_id' => null,
            'note' => null,
            'completed_at' => null,
            'completed_by' => null,
            'outcome_idempotency_key' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'state' => RecurringReviewRunItemState::Completed->value,
            'outcome_kind' => 'no_issue',
            'completed_at' => now(),
        ]);
    }
}
