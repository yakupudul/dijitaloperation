<?php

namespace App\Providers;

use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/** Faz 4b site screen AI routes: competitor classification / analysis and potential backlink sources. */
final class SiteScreenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $routes = $this->app->make(AiRouteRegistry::class);
        $routes->register([
            'key' => AiRouteKeys::COMPETITORS_CLASSIFY,
            'name' => 'Competitor Domain Classes',
            'module' => 'site',
            'description' => 'Rakipler: one call per batch classifies SERP result domains the rules could not place (ticari / bilgi / dizin / haber). Stored once per domain.',
            'default_steps' => AiDefaultSteps::classification(),
        ]);
        $routes->register([
            'key' => AiRouteKeys::COMPETITORS_ANALYZE,
            'name' => 'Competitor Analysis',
            'module' => 'site',
            'description' => 'Rakipler "Analiz et": one call per cluster compares competitor pages with the brand\'s page; suggestions must cite ≥ 2 competitors or a clear gap.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);
        $routes->register([
            'key' => AiRouteKeys::BACKLINKS_SOURCES,
            'name' => 'Backlink Sources',
            'module' => 'site',
            'description' => 'Backlinkler: one call proposes potential link sources by sector and service areas; a fee needs an evidence URL.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);
    }
}
