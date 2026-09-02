<?php

namespace App\Providers;

use App\Events\Collection\CollectionRunCompleted;
use App\Listeners\Collection\QueueWebsiteProjectionAfterCollection;
use App\Services\Collection\Providers\Website\WordPressConnectorDatasetExecutor;
use App\Services\IntelligenceProjection\Website\Adapters\Ga4ProjectionAdapter;
use App\Services\IntelligenceProjection\Website\Adapters\GscProjectionAdapter;
use App\Services\IntelligenceProjection\Website\Adapters\WebsitePublicProjectionAdapter;
use App\Services\IntelligenceProjection\Website\Adapters\WordPressProjectionAdapter;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionAdapterSupport;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionReadService;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionRebuilder;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionSourceAdapterRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class WebsiteIntelligenceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WordPressConnectorDatasetExecutor::class);
        $this->app->tag([WordPressConnectorDatasetExecutor::class], 'collection.dataset_executors');

        $this->app->singleton(WebsiteProjectionAdapterSupport::class);
        $this->app->singleton(WebsitePublicProjectionAdapter::class);
        $this->app->singleton(WordPressProjectionAdapter::class);
        $this->app->singleton(GscProjectionAdapter::class);
        $this->app->singleton(Ga4ProjectionAdapter::class);
        $this->app->tag([
            WebsitePublicProjectionAdapter::class,
            WordPressProjectionAdapter::class,
            GscProjectionAdapter::class,
            Ga4ProjectionAdapter::class,
        ], 'intelligence.source_adapters');

        $this->app->singleton(WebsiteProjectionSourceAdapterRegistry::class);
        $this->app->singleton(WebsiteProjectionRebuilder::class);
        $this->app->singleton(WebsiteProjectionReadService::class);
    }

    public function boot(): void
    {
        Event::listen(CollectionRunCompleted::class, QueueWebsiteProjectionAfterCollection::class);
    }
}
