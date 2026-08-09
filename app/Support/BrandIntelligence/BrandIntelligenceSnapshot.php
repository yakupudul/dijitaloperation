<?php

namespace App\Support\BrandIntelligence;

/**
 * Read-only normalized Brand intelligence facts for modules / future AI.
 * Never fabricates missing values — unknown stays null/empty.
 *
 * @phpstan-type OfferingRow array{name: string, description: ?string}
 * @phpstan-type AudienceRow array{name: string, note: ?string}
 * @phpstan-type MarketRow array{name: string, note: ?string}
 * @phpstan-type GoalRow array{goal: string, note: ?string}
 * @phpstan-type ConversionGoalRow array{type: string, type_label: string, label: ?string, note: ?string}
 * @phpstan-type CompetitorRow array{name: string, url: ?string, note: ?string}
 */
final class BrandIntelligenceSnapshot
{
    /**
     * @param  list<OfferingRow>  $offerings
     * @param  list<string>  $priorityOfferings
     * @param  list<AudienceRow>  $targetAudiences
     * @param  list<MarketRow>  $targetMarkets
     * @param  list<GoalRow>  $businessGoals
     * @param  list<ConversionGoalRow>  $conversionGoals
     * @param  list<string>  $differentiators
     * @param  list<CompetitorRow>  $competitors
     * @param  array{completed: int, total: int, areas: array<string, bool>, label: string}  $completeness
     */
    public function __construct(
        public readonly int $brandId,
        public readonly string $brandName,
        public readonly bool $hasContext,
        public readonly ?string $businessSummary,
        public readonly ?string $businessModel,
        public readonly ?string $businessModelLabel,
        public readonly array $offerings,
        public readonly array $priorityOfferings,
        public readonly array $targetAudiences,
        public readonly array $targetMarkets,
        public readonly array $businessGoals,
        public readonly array $conversionGoals,
        public readonly ?string $positioning,
        public readonly array $differentiators,
        public readonly array $competitors,
        public readonly ?string $importantConstraints,
        public readonly string $source,
        public readonly array $completeness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'brand_id' => $this->brandId,
            'brand_name' => $this->brandName,
            'has_context' => $this->hasContext,
            'business_summary' => $this->businessSummary,
            'business_model' => $this->businessModel,
            'business_model_label' => $this->businessModelLabel,
            'offerings' => $this->offerings,
            'priority_offerings' => $this->priorityOfferings,
            'target_audiences' => $this->targetAudiences,
            'target_markets' => $this->targetMarkets,
            'business_goals' => $this->businessGoals,
            'conversion_goals' => $this->conversionGoals,
            'positioning' => $this->positioning,
            'differentiators' => $this->differentiators,
            'competitors' => $this->competitors,
            'important_constraints' => $this->importantConstraints,
            'source' => $this->source,
            'completeness' => $this->completeness,
        ];
    }
}
