<?php

namespace App\Support\Recommendations;

use App\Models\Finding;
use App\Models\Opportunity;
use App\Support\Recommendations\Dto\RecommendationSourceViewData;

/**
 * A server-side resolved Recommendation source: the canonical model plus its safe projection.
 */
final readonly class ResolvedRecommendationSource
{
    public function __construct(
        public RecommendationSourceReference $reference,
        public Finding|Opportunity $model,
        public RecommendationSourceViewData $viewData,
    ) {}

    public function finding(): ?Finding
    {
        return $this->model instanceof Finding ? $this->model : null;
    }

    public function opportunity(): ?Opportunity
    {
        return $this->model instanceof Opportunity ? $this->model : null;
    }
}
