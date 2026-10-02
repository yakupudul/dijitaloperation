<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Contracts\IntelligenceCore\WebsiteProjectionSourceAdapter;
use App\Services\IntelligenceCore\IntelligenceSourceAdapterRegistry;

final class WebsiteProjectionSourceAdapterRegistry
{
    public function __construct(
        private readonly IntelligenceSourceAdapterRegistry $adapters,
    ) {}

    /** @return array<string, WebsiteProjectionSourceAdapter> */
    public function all(): array
    {
        return array_filter(
            $this->adapters->all(),
            static fn (object $adapter): bool => $adapter instanceof WebsiteProjectionSourceAdapter,
        );
    }
}
