<?php

namespace Database\Factories;

use App\Enums\RecurringReviewCadence;
use App\Enums\RecurringReviewScheduleStatus;
use App\Enums\RecurringReviewScopeKind;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Playbook;
use App\Models\PlaybookRevision;
use App\Models\RecurringReviewSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringReviewSchedule>
 */
class RecurringReviewScheduleFactory extends Factory
{
    protected $model = RecurringReviewSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'scope_kind' => RecurringReviewScopeKind::Brand->value,
            'brand_id' => function (array $attributes): int {
                if (isset($attributes['customer_id'])) {
                    return Brand::factory()->create([
                        'customer_id' => $attributes['customer_id'],
                    ])->id;
                }

                return Brand::factory()->create()->id;
            },
            'digital_asset_id' => null,
            'playbook_id' => function (): int {
                $playbook = Playbook::factory()->create();
                $revision = PlaybookRevision::factory()->create([
                    'playbook_id' => $playbook->id,
                ]);
                $playbook->forceFill(['current_revision_id' => $revision->id])->save();

                return $playbook->id;
            },
            'cadence' => RecurringReviewCadence::Weekly->value,
            'timezone' => 'UTC',
            'starts_at' => now()->startOfDay(),
            'ends_at' => null,
            'status' => RecurringReviewScheduleStatus::Active->value,
            'owner_user_id' => null,
            'default_reviewer_user_id' => null,
            'next_due_at' => now()->addWeek(),
            'created_by' => User::factory(),
            'idempotency_key' => null,
        ];
    }

    public function customerScoped(): static
    {
        return $this->state(fn (): array => [
            'scope_kind' => RecurringReviewScopeKind::Customer->value,
            'brand_id' => null,
            'digital_asset_id' => null,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => RecurringReviewScheduleStatus::Paused->value,
            'next_due_at' => null,
        ]);
    }
}
