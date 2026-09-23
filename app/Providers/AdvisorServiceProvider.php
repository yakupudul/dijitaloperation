<?php

namespace App\Providers;

use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the channel advisor AI routes. Rules run without AI; AI only drafts copy on operator click.
 */
final class AdvisorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GOOGLE_ADS_AD_COPY_DRAFT,
            'name' => 'Google Ads Ad Copy Draft',
            'module' => 'advisor',
            'description' => 'On operator click, drafts responsive search ad headlines and descriptions for one ad group from its keywords, converting search terms and landing page. Copy-paste only; nothing is written to Google Ads.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);
    }
}
