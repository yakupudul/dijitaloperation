<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\BrandMemoryContextProvider;
use App\Enums\BrandExperienceStatus;
use App\Models\BrandExperience;
use App\Support\IntelligenceMemory\Dto\BrandMemoryScope;

/**
 * Prompt 52 — Brand Memory content provider backed by confirmed Brand Experiences.
 *
 * Not Prompt 54 retrieval. No semantic ranking. No Agent injection.
 */
final class ExperienceBrandMemoryContextProvider implements BrandMemoryContextProvider
{
    public function listApplicableReferences(BrandMemoryScope $scope, int $boundedCount = 0): array
    {
        $query = BrandExperience::query()
            ->with('currentRevision')
            ->where('customer_id', $scope->customerId)
            ->where('brand_id', $scope->brandId)
            ->where('status', BrandExperienceStatus::Confirmed->value)
            ->orderByDesc('id');

        if ($boundedCount > 0) {
            $query->limit($boundedCount);
        }

        $refs = [];
        foreach ($query->get() as $experience) {
            $revision = $experience->currentRevision;
            $refs[] = [
                'artifact_id' => 'brand_experience:'.$experience->id,
                'revision' => $revision !== null ? (string) $revision->id : null,
                'citation' => 'Brand Experience #'.$experience->id
                    .($revision !== null ? ' rev '.$revision->revision_number : ''),
            ];
        }

        return $refs;
    }
}
