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

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::META_ADS_CREATIVE_DRAFT,
            'name' => 'Meta Ads Creative Draft',
            'module' => 'advisor',
            'description' => 'On operator click, proposes new creative ideas, primary texts, headlines and descriptions for one fatigued Meta ad from its current copy, numbers and landing page. Copy-paste only; nothing is written to Meta.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GBP_PROFILE_DRAFT,
            'name' => 'Business Profile Description Draft',
            'module' => 'advisor',
            'description' => 'On operator click, drafts a Google Business Profile description and short service descriptions from the profile, brand services, service areas and search keywords. Copy-paste only; nothing is written to the profile.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::MONTHLY_REPORT_COMMENTARY,
            'name' => 'Monthly Report Commentary',
            'module' => 'reports',
            'description' => 'On operator click, writes the plain-language commentary (summary, wins, watch items, next month) of a monthly client report from its frozen numbers. The operator edits it before sharing.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::AI_VISIBILITY_PROBE,
            'name' => 'AI Visibility Probe',
            'module' => 'intel',
            'description' => 'On operator click, asks customer-style questions about a brand\'s services and areas (without naming the brand) to see whether the AI assistant recommends the brand. Model knowledge only; not a live web search.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);
    }
}
