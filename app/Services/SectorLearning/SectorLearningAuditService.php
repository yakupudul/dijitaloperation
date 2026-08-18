<?php

namespace App\Services\SectorLearning;

use App\Models\SectorLearningLineageEntry;
use App\Models\SectorLearningRevision;
use Illuminate\Support\Collection;

/**
 * Privileged platform audit access to contributor lineage.
 *
 * Agents: NO. Customer users: NO. Normal Sector consumers: NO.
 */
final class SectorLearningAuditService
{
    /**
     * @return Collection<int, SectorLearningLineageEntry>
     */
    public function lineageForRevision(int $revisionId): Collection
    {
        return SectorLearningLineageEntry::query()
            ->where('revision_id', $revisionId)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<int>
     */
    public function artifactIdsAffectedByExperience(int $brandExperienceId): array
    {
        $revisionIds = SectorLearningLineageEntry::query()
            ->where('brand_experience_id', $brandExperienceId)
            ->pluck('revision_id')
            ->unique()
            ->all();

        if ($revisionIds === []) {
            return [];
        }

        return SectorLearningRevision::query()
            ->whereIn('id', $revisionIds)
            ->pluck('artifact_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function artifactIdsAffectedByCustomer(int $customerId): array
    {
        $revisionIds = SectorLearningLineageEntry::query()
            ->where('customer_id', $customerId)
            ->pluck('revision_id')
            ->unique()
            ->all();

        if ($revisionIds === []) {
            return [];
        }

        return SectorLearningRevision::query()
            ->whereIn('id', $revisionIds)
            ->pluck('artifact_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
