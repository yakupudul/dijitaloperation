<?php

namespace App\Services\BrandIntelligence;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Support\BrandIntelligence\BrandIntelligenceCompleteness;
use App\Support\BrandIntelligence\BrandIntelligenceSnapshot;
use App\Support\BrandIntelligence\BusinessModelOptions;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use App\Support\BrandIntelligence\Dto\GoalDto;

/**
 * Factual Brand intelligence reader for modules and future AI.
 * Returns normalized facts only — no inference, no external calls, no AI.
 *
 * Identity-bearing goals/priority offerings prefer canonical Goal/Offering entities
 * (stable IDs) with compatibility projection fallback for unmigrated rows.
 */
final class BrandContextProvider
{
    public function __construct(
        private readonly BrandIntelligenceContextReadService $identityRead,
    ) {}

    public function for(Brand $brand): BrandIntelligenceSnapshot
    {
        $brand->loadMissing('intelligenceContext');
        $context = $brand->intelligenceContext;

        return $this->snapshot($brand, $context);
    }

    public function forBrandId(int $brandId): ?BrandIntelligenceSnapshot
    {
        $brand = Brand::query()->with('intelligenceContext')->find($brandId);

        return $brand instanceof Brand ? $this->for($brand) : null;
    }

    private function snapshot(Brand $brand, ?BrandIntelligenceContext $context): BrandIntelligenceSnapshot
    {
        $completeness = BrandIntelligenceCompleteness::for($context);
        $identity = $this->identityRead->for($brand);

        if ($context === null && $identity->businessGoals === [] && $identity->conversionGoals === [] && $identity->priorityOfferings === []) {
            return new BrandIntelligenceSnapshot(
                brandId: $brand->id,
                brandName: $brand->name,
                hasContext: false,
                businessSummary: null,
                businessModel: null,
                businessModelLabel: null,
                offerings: [],
                priorityOfferings: [],
                targetAudiences: [],
                targetMarkets: [],
                businessGoals: [],
                conversionGoals: [],
                positioning: null,
                differentiators: [],
                competitors: [],
                importantConstraints: null,
                source: BrandIntelligenceContext::SOURCE_OPERATOR,
                completeness: $completeness,
            );
        }

        $businessGoals = array_map(static fn (GoalDto $g): array => [
            'id' => $g->id,
            'goal' => $g->label,
            'note' => $g->note,
            'applicability_mode' => $g->applicabilityMode,
            'offering_ids' => $g->offeringIds,
        ], $identity->businessGoals);

        $conversionGoals = array_map(static function (GoalDto $g): array {
            $type = $g->conversionType ?? ConversionGoalTypes::CUSTOM;

            return [
                'id' => $g->id,
                'type' => $type,
                'type_label' => ConversionGoalTypes::label($type),
                'label' => $g->label,
                'note' => $g->note,
                'applicability_mode' => $g->applicabilityMode,
                'offering_ids' => $g->offeringIds,
            ];
        }, $identity->conversionGoals);

        // Compatibility: if entities empty but legacy projection still present (pre-migrate), use legacy.
        if ($businessGoals === [] && $context !== null) {
            $businessGoals = $this->normalizeGoals($context->business_goals);
        }
        if ($conversionGoals === [] && $context !== null) {
            $conversionGoals = $this->normalizeConversionGoals($context->conversion_goals);
        }

        $priorityOfferings = array_map(
            static fn ($o): string => $o->primaryLabel,
            $identity->priorityOfferings,
        );
        if ($priorityOfferings === [] && $context !== null) {
            $priorityOfferings = $this->normalizeStringList($context->priority_offerings);
        }

        return new BrandIntelligenceSnapshot(
            brandId: $brand->id,
            brandName: $brand->name,
            hasContext: $context instanceof BrandIntelligenceContext || $identity->businessGoals !== [] || $identity->offerings !== [],
            businessSummary: $this->nullableString($context?->business_summary),
            businessModel: $this->nullableString($context?->business_model),
            businessModelLabel: BusinessModelOptions::label($context?->business_model),
            offerings: $this->normalizeOfferings($context?->products_services),
            priorityOfferings: $priorityOfferings,
            targetAudiences: $this->normalizeNamedNotes($context?->target_audiences),
            targetMarkets: $this->normalizeNamedNotes($context?->target_markets),
            businessGoals: $businessGoals,
            conversionGoals: $conversionGoals,
            positioning: $this->nullableString($context?->positioning),
            differentiators: $this->normalizeStringList($context?->differentiators),
            competitors: $this->normalizeCompetitors($context?->known_competitors),
            importantConstraints: $this->nullableString($context?->important_constraints),
            source: $context instanceof BrandIntelligenceContext && filled($context->source)
                ? (string) $context->source
                : BrandIntelligenceContext::SOURCE_OPERATOR,
            completeness: $completeness,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<array{name: string, description: ?string}>
     */
    private function normalizeOfferings(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
            if ($name === '') {
                continue;
            }
            $description = isset($row['description']) && is_string($row['description'])
                ? trim($row['description'])
                : '';
            $out[] = [
                'name' => $name,
                'description' => $description === '' ? null : $description,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $value = trim($row);
                if ($value !== '') {
                    $out[] = $value;
                }

                continue;
            }

            if (is_array($row) && isset($row['name']) && is_string($row['name'])) {
                $value = trim($row['name']);
                if ($value !== '') {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{name: string, note: ?string}>
     */
    private function normalizeNamedNotes(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $name = trim($row);
                if ($name !== '') {
                    $out[] = ['name' => $name, 'note' => null];
                }

                continue;
            }

            if (! is_array($row)) {
                continue;
            }

            $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
            if ($name === '') {
                continue;
            }
            $note = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : '';
            $out[] = [
                'name' => $name,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{goal: string, note: ?string}>
     */
    private function normalizeGoals(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $goal = trim($row);
                if ($goal !== '') {
                    $out[] = ['goal' => $goal, 'note' => null];
                }

                continue;
            }

            if (! is_array($row)) {
                continue;
            }

            $goal = isset($row['goal']) && is_string($row['goal'])
                ? trim($row['goal'])
                : (isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '');
            if ($goal === '') {
                continue;
            }
            $note = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : '';
            $out[] = [
                'goal' => $goal,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{type: string, type_label: string, label: ?string, note: ?string}>
     */
    private function normalizeConversionGoals(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = isset($row['type']) && is_string($row['type']) ? trim($row['type']) : '';
            if ($type === '') {
                continue;
            }
            $label = isset($row['label']) && is_string($row['label']) ? trim($row['label']) : '';
            $note = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : '';
            $out[] = [
                'type' => $type,
                'type_label' => ConversionGoalTypes::label($type),
                'label' => $label === '' ? null : $label,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, url: ?string, note: ?string}>
     */
    private function normalizeCompetitors(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
            if ($name === '') {
                continue;
            }
            $url = isset($row['url']) && is_string($row['url']) ? trim($row['url']) : '';
            $note = isset($row['note']) && is_string($row['note']) ? trim($row['note']) : '';
            $out[] = [
                'name' => $name,
                'url' => $url === '' ? null : $url,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $out;
    }
}
