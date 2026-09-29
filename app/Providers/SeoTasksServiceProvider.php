<?php

namespace App\Providers;

use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the brand setup AI route ("Otomatik kur") and the Faz 2 ownership routes (brand candidates, services).
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

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::BRAND_CANDIDATES,
            'name' => 'Brand Candidates',
            'module' => 'ownership',
            'description' => 'Keşfedilen varlıklar: one call per batch groups the discovered accounts exact signals could not place and proposes each brand candidate\'s sector (Business Profile category > site > ads). Proposal only.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::BRAND_SERVICES,
            'name' => 'Brand Services From Pages',
            'module' => 'ownership',
            'description' => 'Hizmet keşfi: one call proposes the brand\'s services from its own service pages (title / URL / H1), matched to the sector\'s service catalog. Proposal only; the operator approves.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);
    }
}
