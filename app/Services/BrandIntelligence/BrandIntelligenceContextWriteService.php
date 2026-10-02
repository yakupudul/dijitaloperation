<?php

namespace App\Services\BrandIntelligence;

use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Enums\OfferingNameProvenance;
use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandGoal;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\User;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use Illuminate\Support\Facades\DB;

/**
 * Atomic BrandIntelligenceContext write path.
 *
 * Identity fields (business_goals, conversion_goals, priority_offerings) are written
 * only as one-way projections from canonical Goal/Offering entities.
 *
 * Target audiences / markets and other non-identity BIC fields remain direct BIC truth.
 */
final class BrandIntelligenceContextWriteService
{
    public function __construct(
        private readonly BrandGoalService $goals,
        private readonly BrandOfferingService $offerings,
        private readonly BrandContextActivityRecorder $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Cleaned form payload (same shape as Filament BIC form)
     */
    public function saveFromForm(Brand $brand, array $data, ?User $actor = null): BrandIntelligenceContext
    {
        $brand = Brand::query()->findOrFail($brand->id);

        return DB::transaction(function () use ($brand, $data, $actor): BrandIntelligenceContext {
            $context = BrandIntelligenceContext::query()->firstOrNew(['brand_id' => $brand->id]);

            $context->business_summary = $this->nullableTrim($data['business_summary'] ?? null);
            $context->business_model = $this->nullableTrim($data['business_model'] ?? null);
            $context->products_services = is_array($data['products_services'] ?? null) ? $data['products_services'] : [];
            $context->target_audiences = is_array($data['target_audiences'] ?? null) ? $data['target_audiences'] : [];
            $context->target_markets = is_array($data['target_markets'] ?? null) ? $data['target_markets'] : [];
            $context->positioning = $this->nullableTrim($data['positioning'] ?? null);
            $context->differentiators = is_array($data['differentiators'] ?? null) ? $data['differentiators'] : [];
            $context->known_competitors = is_array($data['known_competitors'] ?? null) ? $data['known_competitors'] : [];
            $context->important_constraints = $this->nullableTrim($data['important_constraints'] ?? null);
            $context->source = BrandIntelligenceContext::SOURCE_OPERATOR;
            $context->updated_by = $actor?->id;

            if (! $context->exists) {
                BrandIntelligenceContext::withLegacyIdentityProjection(function () use ($context): void {
                    $context->business_goals = [];
                    $context->conversion_goals = [];
                    $context->priority_offerings = [];
                    $context->save();
                });
            } else {
                $context->save();
            }

            $this->syncBusinessGoals($brand, is_array($data['business_goals'] ?? null) ? $data['business_goals'] : [], $actor);
            $this->syncConversionGoals($brand, is_array($data['conversion_goals'] ?? null) ? $data['conversion_goals'] : [], $actor);
            $this->syncPriorityOfferings($brand, is_array($data['priority_offerings'] ?? null) ? $data['priority_offerings'] : [], $actor);

            $this->projectIdentityFields($brand);

            $this->activity->record(
                $brand,
                'BIC_CONTEXT_SAVED',
                BrandIntelligenceContext::class,
                $brand->fresh()->intelligenceContext?->id,
                [],
                $actor,
            );

            return $brand->fresh()->intelligenceContext ?? $context->fresh();
        });
    }

    public function projectIdentityFields(Brand $brand): void
    {
        $context = BrandIntelligenceContext::query()->where('brand_id', $brand->id)->first();
        if (! $context instanceof BrandIntelligenceContext) {
            return;
        }

        $businessGoals = BrandGoal::query()
            ->where('brand_id', $brand->id)
            ->where('kind', GoalKind::Business)
            ->where('status', GoalStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (BrandGoal $g): array => [
                'goal' => $g->label,
                'note' => $g->note,
            ])
            ->values()
            ->all();

        $conversionGoals = BrandGoal::query()
            ->where('brand_id', $brand->id)
            ->where('kind', GoalKind::Conversion)
            ->where('status', GoalStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static function (BrandGoal $g): array {
                $type = is_string($g->conversion_type) && $g->conversion_type !== ''
                    ? $g->conversion_type
                    : ConversionGoalTypes::CUSTOM;
                $typeLabel = ConversionGoalTypes::label($type);
                $storedLabel = $g->label === $typeLabel ? null : $g->label;

                return [
                    'type' => $type,
                    'label' => $storedLabel,
                    'note' => $g->note,
                ];
            })
            ->values()
            ->all();

        $priorityOfferings = BrandOffering::query()
            ->with('primaryName')
            ->where('brand_id', $brand->id)
            ->where('status', OfferingStatus::Active)
            ->whereNotNull('priority_rank')
            ->orderBy('priority_rank')
            ->orderBy('id')
            ->get()
            ->map(static fn (BrandOffering $o): ?string => $o->primaryName?->raw_label)
            ->filter(static fn (?string $label): bool => is_string($label) && $label !== '')
            ->values()
            ->all();

        BrandIntelligenceContext::withLegacyIdentityProjection(function () use ($context, $businessGoals, $conversionGoals, $priorityOfferings): void {
            $context->business_goals = $businessGoals;
            $context->conversion_goals = $conversionGoals;
            $context->priority_offerings = $priorityOfferings;
            $context->save();
        });
    }

    /**
     * @param  list<array{goal?: string, note?: ?string}|mixed>  $rows
     */
    private function syncBusinessGoals(Brand $brand, array $rows, ?User $actor): void
    {
        $desiredKeys = [];
        $order = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = isset($row['goal']) && is_string($row['goal']) ? trim($row['goal']) : '';
            if ($label === '') {
                continue;
            }
            $order++;
            $note = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : null;
            $goal = $this->goals->create(
                brand: $brand,
                kind: GoalKind::Business,
                label: $label,
                note: $note === '' ? null : $note,
                actor: $actor,
                recordActivity: false,
            );
            if ($goal->status === GoalStatus::Archived) {
                $this->goals->restore($goal, $actor);
            }
            $goal->sort_order = $order;
            $goal->note = $note === '' || $note === null ? null : $note;
            $goal->label = $label;
            $goal->save();
            $desiredKeys[] = $goal->normalized_key;
        }

        $this->archiveMissingGoals($brand, GoalKind::Business, $desiredKeys, $actor);
        $this->goals->reorder(
            $brand,
            GoalKind::Business,
            BrandGoal::query()
                ->where('brand_id', $brand->id)
                ->where('kind', GoalKind::Business)
                ->where('status', GoalStatus::Active)
                ->orderBy('sort_order')
                ->pluck('id')
                ->all(),
            $actor,
        );
    }

    /**
     * @param  list<array{type?: string, label?: ?string, note?: ?string}|mixed>  $rows
     */
    private function syncConversionGoals(Brand $brand, array $rows, ?User $actor): void
    {
        $desiredKeys = [];
        $order = 0;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = isset($row['type']) && is_string($row['type']) ? trim($row['type']) : '';
            if ($type === '' || ! array_key_exists($type, ConversionGoalTypes::options())) {
                continue;
            }
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';
            $display = $label !== '' ? $label : ConversionGoalTypes::label($type);
            $note = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : null;
            $order++;

            $goal = $this->goals->create(
                brand: $brand,
                kind: GoalKind::Conversion,
                label: $display,
                note: $note === '' ? null : $note,
                conversionType: $type,
                actor: $actor,
                recordActivity: false,
            );
            if ($goal->status === GoalStatus::Archived) {
                $this->goals->restore($goal, $actor);
            }
            $goal->conversion_type = $type;
            $goal->sort_order = $order;
            $goal->note = $note === '' || $note === null ? null : $note;
            $goal->label = $display;
            $goal->save();
            $desiredKeys[] = $goal->normalized_key;
        }

        $this->archiveMissingGoals($brand, GoalKind::Conversion, $desiredKeys, $actor);
    }

    /**
     * @param  list<string|array{name?: string}|mixed>  $rows
     */
    private function syncPriorityOfferings(Brand $brand, array $rows, ?User $actor): void
    {
        $orderedIds = [];
        foreach ($rows as $row) {
            $label = null;
            if (is_string($row)) {
                $label = trim($row);
            } elseif (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                $label = trim($row['name']);
            }
            if ($label === null || $label === '') {
                continue;
            }

            $result = $this->offerings->resolveOrCreate(
                brand: $brand,
                label: $label,
                provenance: OfferingNameProvenance::PrimaryOperator,
                actor: $actor,
                recordActivity: false,
            );
            $orderedIds[] = $result['offering']->id;
        }

        $this->offerings->setPriorityOrder($brand, $orderedIds, $actor, recordActivity: false);
    }

    /**
     * @param  list<string>  $desiredNormalizedKeys
     */
    private function archiveMissingGoals(Brand $brand, GoalKind $kind, array $desiredNormalizedKeys, ?User $actor): void
    {
        $active = BrandGoal::query()
            ->where('brand_id', $brand->id)
            ->where('kind', $kind)
            ->where('status', GoalStatus::Active)
            ->get();

        foreach ($active as $goal) {
            if (! in_array($goal->normalized_key, $desiredNormalizedKeys, true)) {
                $this->goals->archive($goal, $actor);
            }
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
