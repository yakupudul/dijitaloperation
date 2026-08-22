<?php

namespace MoxDop\GoogleBusinessProfile\Collection;

use App\Contracts\Integrations\CollectsBoundProviderData;
use App\Models\CoreAssetBinding;
use App\Models\Run;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;

/**
 * Canonical GBP module collector entrypoint.
 *
 * The module remains the single registry owner for the google_business_profile
 * capability. Real provider collection is delegated to the full application
 * collector so Data Sources -> Collect Now pulls all supported GBP datasets
 * without registering a second competing collector.
 */
final class GbpLocationBoundCollector implements CollectsBoundProviderData
{
    public const string MODULE_ID = 'google-business-profile';

    public const string CAPABILITY = 'google_business_profile';

    public function __construct(
        private readonly GoogleBusinessProfileBoundCollector $collector,
    ) {}

    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function moduleId(): string
    {
        return self::MODULE_ID;
    }

    public function collect(CoreAssetBinding $binding): Run
    {
        return $this->collector->collect($binding);
    }
}
