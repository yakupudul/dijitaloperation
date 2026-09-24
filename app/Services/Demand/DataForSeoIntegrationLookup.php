<?php

namespace App\Services\Demand;

use App\Models\CoreIntegration;
use App\Support\Integrations\ProviderRegistry;

/** The active DataForSEO integration (core-side lookup; module resolvers stay inside their module). */
final class DataForSeoIntegrationLookup
{
    public function active(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->where('provider', ProviderRegistry::DATAFORSEO)
            ->where('status', CoreIntegration::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();
    }
}
