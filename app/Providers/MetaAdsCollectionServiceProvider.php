<?php

namespace App\Providers;

use App\Services\Collection\Providers\MetaAds\MetaAdsProfessionalDatasetExecutor;
use Illuminate\Support\ServiceProvider;

final class MetaAdsCollectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetaAdsProfessionalDatasetExecutor::class);
        $this->app->tag([
            MetaAdsProfessionalDatasetExecutor::class,
        ], 'collection.dataset_executors');
    }
}
