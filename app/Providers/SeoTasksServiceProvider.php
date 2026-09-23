<?php

namespace App\Providers;

use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the SEO Tasks AI route. Engine services are plain container-resolved classes.
 */
final class SeoTasksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SEO_TASKS_SITE_UNDERSTANDING,
            'name' => 'SEO Tasks Site Understanding',
            'module' => 'seo_tasks',
            'description' => 'When a Brand has no services defined, reads the stored website pages, Search Console queries and GA4 landing pages to infer what the business sells and to whom. Output is a review-only suggestion; nothing is written to the Brand automatically.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::ANTHROPIC,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::ANTHROPIC),
                ],
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::SEO_TASKS_CONTENT_PLANNER,
            'name' => 'SEO Tasks Content Planner',
            'module' => 'seo_tasks',
            'description' => 'Rule-selected SEO task candidates get operator-ready Turkish titles, checklists and content briefs. One call per plan run; never adds or scores tasks.',
            'default_steps' => [
                [
                    'provider' => AiProviderCatalog::ANTHROPIC,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::ANTHROPIC),
                ],
                [
                    'provider' => AiProviderCatalog::OPENAI,
                    'model' => AiProviderCatalog::defaultModel(AiProviderCatalog::OPENAI),
                ],
            ],
        ]);
    }
}
