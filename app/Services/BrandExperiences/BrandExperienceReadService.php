<?php

namespace App\Services\BrandExperiences;

use App\Enums\BrandExperienceStatus;
use App\Models\BrandExperience;
use App\Models\BrandExperienceRevision;
use App\Support\IntelligenceMemory\Dto\BrandMemoryScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Brand-scoped Brand Experience reads — no semantic/vector retrieval.
 */
final class BrandExperienceReadService
{
    /**
     * @param  array{status?: string|null, channel?: string|null, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, BrandExperience>
     */
    public function listForBrand(BrandMemoryScope $scope, array $filters = []): LengthAwarePaginator
    {
        $query = BrandExperience::query()
            ->with(['currentRevision.goals', 'currentRevision.offerings', 'currentRevision.evidenceLinks'])
            ->where('customer_id', $scope->customerId)
            ->where('brand_id', $scope->brandId);

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['channel']) && is_string($filters['channel']) && $filters['channel'] !== '') {
            $query->whereHas('currentRevision', function ($q) use ($filters): void {
                $q->where('channel', $filters['channel']);
            });
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        return $query
            ->orderByDesc(
                BrandExperienceRevision::query()
                    ->select('action_occurred_at')
                    ->whereColumn('brand_experience_revisions.id', 'brand_experiences.current_revision_id')
                    ->limit(1)
            )
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return list<BrandExperience>
     */
    public function listConfirmedForBrand(BrandMemoryScope $scope, int $boundedCount = 50): array
    {
        $query = BrandExperience::query()
            ->with(['currentRevision'])
            ->where('customer_id', $scope->customerId)
            ->where('brand_id', $scope->brandId)
            ->where('status', BrandExperienceStatus::Confirmed->value)
            ->orderByDesc('id');

        if ($boundedCount > 0) {
            $query->limit($boundedCount);
        }

        return $query->get()->all();
    }

    public function getByIdForBrand(BrandMemoryScope $scope, int $experienceId): ?BrandExperience
    {
        return BrandExperience::query()
            ->with([
                'currentRevision.goals.brandGoal',
                'currentRevision.offerings.brandOffering',
                'currentRevision.evidenceLinks.evidence',
                'revisions',
            ])
            ->where('customer_id', $scope->customerId)
            ->where('brand_id', $scope->brandId)
            ->where('id', $experienceId)
            ->first();
    }
}
