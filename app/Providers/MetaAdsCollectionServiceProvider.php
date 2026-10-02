<?php

namespace App\Providers;

use App\Services\Collection\Providers\MetaAds\MetaAdsProfessionalDatasetExecutor;
use App\Services\Integrations\Meta\MetaApiClient;
use Illuminate\Support\ServiceProvider;

final class MetaAdsCollectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // All Meta collectors share the canonical client. Unsupported provider
        // request shapes are handled at this boundary; no second compatibility
        // client may diverge in telemetry or safe provider-error propagation.
        $this->app->singleton(MetaApiClient::class);

        $this->app->singleton(MetaAdsProfessionalDatasetExecutor::class);
        $this->app->tag([
            MetaAdsProfessionalDatasetExecutor::class,
        ], 'collection.dataset_executors');
    }
}
