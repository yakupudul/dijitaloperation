<?php

namespace App\Services\SectorLearning;

use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Models\BrandExperience;
use App\Support\SectorLearning\Dto\InternalSectorContribution;
use Illuminate\Support\Collection;

/**
 * Privileged cross-brand contribution access — Sector Learning infrastructure only.
 *
 * NOT a generic cross-tenant query API.
 * Agents / customer-scoped services must not call this.
 */
final class SectorLearningContributionRepository
{
    public function __construct(
        private readonly SectorLearningContributionProjector $projector,
    ) {}

    /**
     * @return list<InternalSectorContribution>
     */
    public function eligibleContributionsForSector(string $sectorCode): array
    {
        /** @var Collection<int, BrandExperience> $experiences */
        $experiences = BrandExperience::query()
            ->with(['currentRevision', 'brand.customer'])
            ->where('status', BrandExperienceStatus::Confirmed->value)
            ->whereHas('brand', function ($q) use ($sectorCode): void {
                $q->where('sector', $sectorCode)
                    ->orWhereHas('customer', fn ($cq) => $cq->where('industry', $sectorCode));
            })
            ->whereHas('currentRevision', function ($q): void {
                $q->whereIn('support_status', [
                    BrandExperienceSupportStatus::Sufficient->value,
                    BrandExperienceSupportStatus::Partial->value,
                ]);
            })
            ->get();

        $out = [];
        foreach ($experiences as $experience) {
            $result = $this->projector->project($experience);
            if (($result['ok'] ?? false) !== true) {
                continue;
            }
            /** @var InternalSectorContribution $contribution */
            $contribution = $result['contribution'];
            if ($contribution->projection->sectorCode !== $sectorCode) {
                continue;
            }
            $out[] = $contribution;
        }

        return $out;
    }
}
