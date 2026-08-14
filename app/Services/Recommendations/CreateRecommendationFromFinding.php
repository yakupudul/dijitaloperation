<?php

namespace App\Services\Recommendations;

use App\Enums\RecommendationOrigin;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\User;
use App\Support\Recommendations\RecommendationSourceReference;

/**
 * Finding-sourced Recommendation creation. Thin wrapper over CreateRecommendation:
 * the source is resolved server-side and can never be swapped by the caller.
 */
final class CreateRecommendationFromFinding
{
    public function __construct(
        private readonly CreateRecommendation $createRecommendation,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     */
    public function create(
        Finding|int $finding,
        array $content,
        RecommendationOrigin $origin = RecommendationOrigin::Operator,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): Recommendation {
        return $this->createRecommendation->create(
            RecommendationSourceReference::fromFinding($finding),
            $content,
            $origin,
            $actor,
            $idempotencyKey,
        );
    }
}
