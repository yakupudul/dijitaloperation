<?php

namespace App\Services\BrandIntelligence;

use App\Enums\GoalApplicabilityMode;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Models\Brand;
use App\Models\BrandGoal;
use App\Models\BrandOffering;
use App\Models\User;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical write path for Brand Goals.
 *
 * Does not create Evidence, Findings, Opportunities, Recommendations, Tasks, or AI calls.
 * Does not infer Goals from provider conversions.
 */
final class BrandGoalService
{
    public function __construct(
        private readonly IdentityLabelNormalizer $normalizer,
        private readonly BrandContextActivityRecorder $activity,
    ) {}

    public function create(
        Brand $brand,
        GoalKind $kind,
        string $label,
        ?string $note = null,
        ?string $conversionType = null,
        GoalApplicabilityMode $applicability = GoalApplicabilityMode::BrandWide,
        array $offeringIds = [],
        ?int $sortOrder = null,
        ?User $actor = null,
        bool $recordActivity = true,
    ): BrandGoal {
        $label = trim($label);
        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Goal label is required.']);
        }

        $normalized = $this->normalizer->normalize($label);
        if ($normalized === '') {
            throw ValidationException::withMessages(['label' => 'Goal label is empty after normalization.']);
        }

        if ($kind === GoalKind::Conversion && $conversionType !== null && $conversionType === '') {
            $conversionType = null;
        }

        $this->assertApplicability($brand, $applicability, $offeringIds);

        try {
            return DB::transaction(function () use (
                $brand,
                $kind,
                $label,
                $normalized,
                $note,
                $conversionType,
                $applicability,
                $offeringIds,
                $sortOrder,
                $actor,
                $recordActivity,
            ): BrandGoal {
                $existing = BrandGoal::query()
                    ->where('brand_id', $brand->id)
                    ->where('kind', $kind)
                    ->where('normalized_key', $normalized)
                    ->first();

                if ($existing instanceof BrandGoal) {
                    return $existing;
                }

                $order = $sortOrder ?? ((int) BrandGoal::query()
                    ->where('brand_id', $brand->id)
                    ->where('kind', $kind)
                    ->max('sort_order')) + 1;

                $goal = BrandGoal::query()->create([
                    'brand_id' => $brand->id,
                    'kind' => $kind,
                    'label' => $label,
                    'normalized_key' => $normalized,
                    'note' => $this->nullableTrim($note),
                    'conversion_type' => $conversionType,
                    'status' => GoalStatus::Active,
                    'applicability_mode' => $applicability,
                    'sort_order' => $order,
                ]);

                $this->syncOfferings($goal, $applicability, $offeringIds);

                if ($recordActivity) {
                    $this->activity->record(
                        $brand,
                        'GOAL_CREATED',
                        BrandGoal::class,
                        $goal->id,
                        ['kind' => $kind->value, 'label' => $label],
                        $actor,
                    );
                }

                return $goal->fresh(['offerings']) ?? $goal;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = BrandGoal::query()
                ->where('brand_id', $brand->id)
                ->where('kind', $kind)
                ->where('normalized_key', $normalized)
                ->first();

            if ($existing instanceof BrandGoal) {
                return $existing;
            }

            throw ValidationException::withMessages(['label' => 'Goal already exists for this Brand and kind.']);
        }
    }

    public function rename(BrandGoal $goal, string $newLabel, ?User $actor = null): BrandGoal
    {
        $goal = BrandGoal::query()->findOrFail($goal->id);
        $newLabel = trim($newLabel);
        if ($newLabel === '') {
            throw ValidationException::withMessages(['label' => 'Goal label is required.']);
        }

        $normalized = $this->normalizer->normalize($newLabel);
        $oldLabel = $goal->label;

        if ($goal->normalized_key === $normalized) {
            $goal->label = $newLabel;
            $goal->save();

            return $goal;
        }

        $collision = BrandGoal::query()
            ->where('brand_id', $goal->brand_id)
            ->where('kind', $goal->kind)
            ->where('normalized_key', $normalized)
            ->where('id', '!=', $goal->id)
            ->exists();

        if ($collision) {
            throw ValidationException::withMessages(['label' => 'Another Goal with this label already exists for this kind.']);
        }

        $goal->label = $newLabel;
        $goal->normalized_key = $normalized;
        $goal->save();

        $this->activity->record(
            $goal->brand,
            'GOAL_RENAMED',
            BrandGoal::class,
            $goal->id,
            ['from' => $oldLabel, 'to' => $newLabel],
            $actor,
        );

        return $goal;
    }

    public function archive(BrandGoal $goal, ?User $actor = null): BrandGoal
    {
        $goal = BrandGoal::query()->findOrFail($goal->id);
        if ($goal->status === GoalStatus::Archived) {
            return $goal;
        }

        $goal->status = GoalStatus::Archived;
        $goal->save();

        $this->activity->record(
            $goal->brand,
            'GOAL_ARCHIVED',
            BrandGoal::class,
            $goal->id,
            ['label' => $goal->label],
            $actor,
        );

        return $goal;
    }

    public function restore(BrandGoal $goal, ?User $actor = null): BrandGoal
    {
        $goal = BrandGoal::query()->findOrFail($goal->id);
        $goal->status = GoalStatus::Active;
        $goal->save();

        $this->activity->record(
            $goal->brand,
            'GOAL_RESTORED',
            BrandGoal::class,
            $goal->id,
            ['label' => $goal->label],
            $actor,
        );

        return $goal;
    }

    /**
     * @param  list<int>  $offeringIds
     */
    public function setApplicability(
        BrandGoal $goal,
        GoalApplicabilityMode $mode,
        array $offeringIds = [],
        ?User $actor = null,
    ): BrandGoal {
        $goal = BrandGoal::query()->with('brand')->findOrFail($goal->id);
        $this->assertApplicability($goal->brand, $mode, $offeringIds);

        return DB::transaction(function () use ($goal, $mode, $offeringIds, $actor): BrandGoal {
            $goal->applicability_mode = $mode;
            $goal->save();
            $this->syncOfferings($goal, $mode, $offeringIds);

            $this->activity->record(
                $goal->brand,
                'GOAL_APPLICABILITY_CHANGED',
                BrandGoal::class,
                $goal->id,
                [
                    'mode' => $mode->value,
                    'offering_ids' => array_values(array_map('intval', $offeringIds)),
                ],
                $actor,
            );

            return $goal->fresh(['offerings']) ?? $goal;
        });
    }

    /**
     * @param  list<int>  $orderedGoalIds
     */
    public function reorder(Brand $brand, GoalKind $kind, array $orderedGoalIds, ?User $actor = null): void
    {
        DB::transaction(function () use ($brand, $kind, $orderedGoalIds): void {
            $goals = BrandGoal::query()
                ->where('brand_id', $brand->id)
                ->where('kind', $kind)
                ->whereIn('id', $orderedGoalIds)
                ->get()
                ->keyBy('id');

            foreach (array_values($orderedGoalIds) as $index => $id) {
                $goal = $goals->get($id);
                if (! $goal instanceof BrandGoal) {
                    throw ValidationException::withMessages(['goals' => 'Goal does not belong to this Brand.']);
                }
                $goal->sort_order = $index + 1;
                $goal->save();
            }
        });
    }

    /**
     * @param  list<int>  $offeringIds
     */
    private function assertApplicability(Brand $brand, GoalApplicabilityMode $mode, array $offeringIds): void
    {
        if ($mode === GoalApplicabilityMode::BrandWide) {
            if ($offeringIds !== []) {
                throw ValidationException::withMessages([
                    'offerings' => 'Brand-wide Goals must not carry Offering relations.',
                ]);
            }

            return;
        }

        if ($offeringIds === []) {
            throw ValidationException::withMessages([
                'offerings' => 'Specific-Offering Goals require at least one Offering.',
            ]);
        }

        $ids = array_values(array_unique(array_map('intval', $offeringIds)));
        $count = BrandOffering::query()
            ->where('brand_id', $brand->id)
            ->whereIn('id', $ids)
            ->count();

        if ($count !== count($ids)) {
            throw ValidationException::withMessages([
                'offerings' => 'All Offerings must belong to the same Brand.',
            ]);
        }
    }

    /**
     * @param  list<int>  $offeringIds
     */
    private function syncOfferings(BrandGoal $goal, GoalApplicabilityMode $mode, array $offeringIds): void
    {
        if ($mode === GoalApplicabilityMode::BrandWide) {
            $goal->offerings()->sync([]);

            return;
        }

        $goal->offerings()->sync(array_values(array_unique(array_map('intval', $offeringIds))));
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
