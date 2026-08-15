<?php

namespace Database\Factories;

use App\Enums\RecurringReviewOccurrenceKind;
use App\Enums\RecurringReviewRunStatus;
use App\Enums\RecurringReviewScopeKind;
use App\Models\Customer;
use App\Models\Playbook;
use App\Models\PlaybookRevision;
use App\Models\RecurringReviewRun;
use App\Models\RecurringReviewSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringReviewRun>
 */
class RecurringReviewRunFactory extends Factory
{
    protected $model = RecurringReviewRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'schedule_id' => RecurringReviewSchedule::factory(),
            'occurrence_key' => 'scheduled:'.now()->format('Y-m-d\TH:i:s'),
            'occurrence_kind' => RecurringReviewOccurrenceKind::Scheduled->value,
            'due_at' => now(),
            'playbook_id' => function (array $attributes): int {
                if (isset($attributes['schedule_id'])) {
                    $playbookId = RecurringReviewSchedule::query()
                        ->whereKey($attributes['schedule_id'])
                        ->value('playbook_id');
                    if ($playbookId !== null) {
                        return (int) $playbookId;
                    }
                }

                $playbook = Playbook::factory()->create();
                $revision = PlaybookRevision::factory()->create(['playbook_id' => $playbook->id]);
                $playbook->forceFill(['current_revision_id' => $revision->id])->save();

                return $playbook->id;
            },
            'playbook_revision_id' => function (array $attributes): int {
                if (isset($attributes['playbook_id'])) {
                    $revisionId = Playbook::query()
                        ->whereKey($attributes['playbook_id'])
                        ->value('current_revision_id');
                    if ($revisionId !== null) {
                        return (int) $revisionId;
                    }
                }

                return PlaybookRevision::factory()->create([
                    'playbook_id' => $attributes['playbook_id'] ?? Playbook::factory(),
                ])->id;
            },
            'customer_id' => function (array $attributes): int {
                if (isset($attributes['schedule_id'])) {
                    return (int) RecurringReviewSchedule::query()
                        ->whereKey($attributes['schedule_id'])
                        ->value('customer_id');
                }

                return Customer::factory()->create()->id;
            },
            'scope_kind' => RecurringReviewScopeKind::Brand->value,
            'brand_id' => function (array $attributes): ?int {
                if (isset($attributes['schedule_id'])) {
                    $brandId = RecurringReviewSchedule::query()
                        ->whereKey($attributes['schedule_id'])
                        ->value('brand_id');

                    return $brandId !== null ? (int) $brandId : null;
                }

                return null;
            },
            'digital_asset_id' => null,
            'service_scope_context_json' => [
                'service_scope_context' => 'SERVICE_NOT_RELEVANT',
                'reasons' => [],
            ],
            'reviewer_user_id' => null,
            'status' => RecurringReviewRunStatus::Scheduled->value,
            'started_at' => null,
            'completed_at' => null,
            'summary_json' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => RecurringReviewRunStatus::InProgress->value,
            'started_at' => now(),
        ]);
    }
}
