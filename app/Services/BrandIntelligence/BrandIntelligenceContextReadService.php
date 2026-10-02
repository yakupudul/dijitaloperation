<?php

namespace App\Services\BrandIntelligence;

use App\Enums\GoalApplicabilityMode;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Enums\OfferingNameKind;
use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandGoal;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandOfferingName;
use App\Support\BrandIntelligence\Dto\BrandIntelligenceContextReadDto;
use App\Support\BrandIntelligence\Dto\GoalDto;
use App\Support\BrandIntelligence\Dto\OfferingDto;

/**
 * Stable query boundary for Brand intelligence identity context.
 * No Demo fallback. Empty means empty.
 */
final class BrandIntelligenceContextReadService
{
    public function for(Brand $brand): BrandIntelligenceContextReadDto
    {
        $brand->loadMissing('intelligenceContext');
        $context = $brand->intelligenceContext;

        $goals = BrandGoal::query()
            ->with('offerings:id')
            ->where('brand_id', $brand->id)
            ->where('status', GoalStatus::Active)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $offerings = BrandOffering::query()
            ->with(['names' => static function ($q): void {
                $q->where('is_active', true)->orderByDesc('is_primary')->orderBy('id');
            }])
            ->where('brand_id', $brand->id)
            ->where('status', OfferingStatus::Active)
            ->orderByRaw('priority_rank is null')
            ->orderBy('priority_rank')
            ->orderBy('id')
            ->get();

        $businessGoals = [];
        $conversionGoals = [];
        foreach ($goals as $goal) {
            $dto = $this->goalDto($goal);
            if ($goal->kind === GoalKind::Business) {
                $businessGoals[] = $dto;
            } else {
                $conversionGoals[] = $dto;
            }
        }

        $offeringDtos = [];
        $priorityDtos = [];
        foreach ($offerings as $offering) {
            $dto = $this->offeringDto($offering);
            $offeringDtos[] = $dto;
            if ($dto->isPriority) {
                $priorityDtos[] = $dto;
            }
        }

        usort($priorityDtos, static fn (OfferingDto $a, OfferingDto $b): int => ($a->priorityRank ?? 0) <=> ($b->priorityRank ?? 0));

        return new BrandIntelligenceContextReadDto(
            brandId: $brand->id,
            brandName: $brand->name,
            hasContext: $context instanceof BrandIntelligenceContext,
            contextId: $context?->id,
            businessGoals: $businessGoals,
            conversionGoals: $conversionGoals,
            offerings: $offeringDtos,
            priorityOfferings: $priorityDtos,
            targetAudiences: $this->namedNotes($context?->target_audiences),
            targetMarkets: $this->namedNotes($context?->target_markets),
            businessSummary: $this->nullableString($context?->business_summary),
            businessModel: $this->nullableString($context?->business_model),
            positioning: $this->nullableString($context?->positioning),
            importantConstraints: $this->nullableString($context?->important_constraints),
            source: $context instanceof BrandIntelligenceContext && filled($context->source)
                ? (string) $context->source
                : BrandIntelligenceContext::SOURCE_OPERATOR,
        );
    }

    private function goalDto(BrandGoal $goal): GoalDto
    {
        $offeringIds = $goal->applicability_mode === GoalApplicabilityMode::BrandWide
            ? []
            : $goal->offerings->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();

        return new GoalDto(
            id: $goal->id,
            kind: $goal->kind->value,
            label: $goal->label,
            note: $goal->note,
            conversionType: $goal->conversion_type,
            status: $goal->status->value,
            applicabilityMode: $goal->applicability_mode->value,
            offeringIds: $offeringIds,
            sortOrder: (int) $goal->sort_order,
        );
    }

    private function offeringDto(BrandOffering $offering): OfferingDto
    {
        $primary = null;
        $aliases = [];
        foreach ($offering->names as $name) {
            if (! $name instanceof BrandOfferingName || ! $name->is_active) {
                continue;
            }
            if ($name->is_primary || $name->name_kind === OfferingNameKind::Primary) {
                $primary = $name->raw_label;
            } else {
                $aliases[] = $name->raw_label;
            }
        }

        return new OfferingDto(
            id: $offering->id,
            primaryLabel: $primary ?? '',
            aliases: $aliases,
            status: $offering->status->value,
            priorityRank: $offering->priority_rank,
            isPriority: $offering->priority_rank !== null,
        );
    }

    /**
     * @return list<array{name: string, note: ?string}>
     */
    private function namedNotes(mixed $rows): array
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
            $out[] = ['name' => $name, 'note' => $note === '' ? null : $note];
        }

        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
