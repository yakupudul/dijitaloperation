<?php

namespace App\Providers;

use App\Services\IntelligenceCore\Identity\BusinessActionIdentityResolver;
use App\Services\IntelligenceCore\Identity\EntityIdentityResolver;
use App\Services\IntelligenceCore\Identity\PageIdentityResolver;
use App\Services\IntelligenceCore\Identity\SearchTermIdentityResolver;
use App\Services\IntelligenceCore\Identity\SearchTermNormalizer;
use App\Services\IntelligenceCore\Identity\UrlJoinKeyNormalizer;
use App\Services\IntelligenceCore\IntelligenceCapabilityRegistry;
use App\Services\IntelligenceCore\IntelligenceContractCompatibilityGuard;
use App\Services\IntelligenceCore\IntelligenceCoreRegistryLoader;
use App\Services\IntelligenceCore\IntelligenceMetricFactory;
use App\Services\IntelligenceCore\IntelligenceMetricRegistry;
use App\Services\IntelligenceCore\IntelligenceSourceAdapterRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class IntelligenceCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IntelligenceCoreRegistryLoader::class);
        $this->app->singleton(IntelligenceCapabilityRegistry::class);
        $this->app->singleton(IntelligenceMetricRegistry::class);
        $this->app->singleton(IntelligenceMetricFactory::class);
        $this->app->singleton(IntelligenceContractCompatibilityGuard::class);
        $this->app->singleton(UrlJoinKeyNormalizer::class);
        $this->app->singleton(SearchTermNormalizer::class);
        $this->app->singleton(PageIdentityResolver::class);
        $this->app->singleton(SearchTermIdentityResolver::class);
        $this->app->singleton(EntityIdentityResolver::class);
        $this->app->singleton(BusinessActionIdentityResolver::class);

        $this->app->singleton(
            IntelligenceSourceAdapterRegistry::class,
            static fn (Application $app): IntelligenceSourceAdapterRegistry => new IntelligenceSourceAdapterRegistry(
                adapters: $app->tagged('intelligence.source_adapters'),
                loader: $app->make(IntelligenceCoreRegistryLoader::class),
                capabilities: $app->make(IntelligenceCapabilityRegistry::class),
                metrics: $app->make(IntelligenceMetricRegistry::class),
            ),
        );
    }
}
