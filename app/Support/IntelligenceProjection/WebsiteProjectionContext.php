<?php

namespace App\Support\IntelligenceProjection;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class WebsiteProjectionContext
{
    public function __construct(
        public readonly DigitalAsset $websiteAsset,
        public readonly WebsiteIntelligenceProjectionRun $projectionRun,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
    ) {
        if ($websiteAsset->getKey() === null || $websiteAsset->type !== 'website') {
            throw new InvalidArgumentException('Website Projection requires a persisted Website Digital Asset.');
        }

        if ($periodStart->isAfter($periodEnd)) {
            throw new InvalidArgumentException('Website Projection period start must not be after period end.');
        }
    }
}
