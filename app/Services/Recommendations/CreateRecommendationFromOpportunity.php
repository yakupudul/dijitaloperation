<?php

namespace App\Services\Recommendations;

use App\Enums\RecommendationOrigin;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\User;
use App\Support\Recommendations\RecommendationSourceReference;

/**
 * Opportunity-sourced Recommendation creation. No placeholder Finding is ever
 * fabricated to make an Opportunity-sourced Recommendation fit the legacy shape.
 */
final class CreateRecommendationFromOpportunity
{
    public function __construct(
        private readonly CreateRecommendation $createRecommendation,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     */
    public function create(
        Opportunity|int $opportunity,
        array $content,
        RecommendationOrigin $origin = RecommendationOrigin::Operator,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): Recommendation {
        return $this->createRecommendation->create(
            RecommendationSourceReference::fromOpportunity($opportunity),
            $content,
            $origin,
            $actor,
            $idempotencyKey,
        );
    }
}
