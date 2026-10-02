<?php

namespace App\Support\BrandIntelligence\Dto;

final class BrandIntelligenceContextReadDto
{
    /**
     * @param  list<GoalDto>  $businessGoals
     * @param  list<GoalDto>  $conversionGoals
     * @param  list<OfferingDto>  $offerings
     * @param  list<OfferingDto>  $priorityOfferings
     * @param  list<array{name: string, note: ?string}>  $targetAudiences
     * @param  list<array{name: string, note: ?string}>  $targetMarkets
     */
    public function __construct(
        public readonly int $brandId,
        public readonly string $brandName,
        public readonly bool $hasContext,
        public readonly ?int $contextId,
        public readonly array $businessGoals,
        public readonly array $conversionGoals,
        public readonly array $offerings,
        public readonly array $priorityOfferings,
        public readonly array $targetAudiences,
        public readonly array $targetMarkets,
        public readonly ?string $businessSummary,
        public readonly ?string $businessModel,
        public readonly ?string $positioning,
        public readonly ?string $importantConstraints,
        public readonly string $source,
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
            'context_id' => $this->contextId,
            'business_goals' => array_map(static fn (GoalDto $g): array => $g->toArray(), $this->businessGoals),
            'conversion_goals' => array_map(static fn (GoalDto $g): array => $g->toArray(), $this->conversionGoals),
            'offerings' => array_map(static fn (OfferingDto $o): array => $o->toArray(), $this->offerings),
            'priority_offerings' => array_map(static fn (OfferingDto $o): array => $o->toArray(), $this->priorityOfferings),
            'target_audiences' => $this->targetAudiences,
            'target_markets' => $this->targetMarkets,
            'business_summary' => $this->businessSummary,
            'business_model' => $this->businessModel,
            'positioning' => $this->positioning,
            'important_constraints' => $this->importantConstraints,
            'source' => $this->source,
        ];
    }
}
