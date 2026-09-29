<?php

namespace App\Providers;

use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the brand setup AI route ("Otomatik kur").
 */
final class SeoTasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::BRAND_SETUP,
            'name' => 'Brand Setup Assistant',
            'module' => 'brand_setup',
            'description' => '"Otomatik kur": proposes a brand\'s services from its own website pages, Search Console queries and crawl candidates, matched to the service catalog. Review-only until the operator approves.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);
    }
}
