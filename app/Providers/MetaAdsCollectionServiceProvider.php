<?php

namespace App\Providers;

use App\Services\Collection\Providers\MetaAds\MetaAdsProfessionalDatasetExecutor;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaApiClientCompatibility;
use Illuminate\Support\ServiceProvider;

final class MetaAdsCollectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Keep one shared Meta API boundary for both the legacy entity snapshot
        // collector and Professional V2 families. The compatibility client only
        // translates provider behaviours that Meta no longer accepts and passes
        // every other request through to the canonical MetaApiClient.
        $this->app->singleton(MetaApiClientCompatibility::class);
        $this->app->singleton(MetaApiClient::class, fn ($app) => $app->make(MetaApiClientCompatibility::class));

        $this->app->singleton(MetaAdsProfessionalDatasetExecutor::class);
        $this->app->tag([
            MetaAdsProfessionalDatasetExecutor::class,
        ], 'collection.dataset_executors');
    }
}
