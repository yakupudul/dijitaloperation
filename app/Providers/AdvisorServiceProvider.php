<?php

namespace App\Providers;

use App\Support\Ai\AiDefaultSteps;
use App\Support\Ai\AiRouteKeys;
use App\Support\Ai\AiRouteRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Business Profile AI routes (review reply, post draft). Rule engines run without AI.
 */
final class AdvisorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GBP_REVIEW_REPLY,
            'name' => 'Google Review Reply Draft',
            'module' => 'advisor',
            'description' => 'On operator click, drafts the owner\'s reply to one Google review of the brand (reviewer name not sent, sector compliance rules applied). Copy-paste only; nothing is posted.',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);

        $this->app->make(AiRouteRegistry::class)->register([
            'key' => AiRouteKeys::GBP_POST_DRAFT,
            'name' => 'Business Profile Post Draft',
            'module' => 'advisor',
            'description' => 'On operator click, drafts the next Google Business Profile post from the brand\'s services, service areas, profile searches and recent posts. It fills the post form; publishing needs Admin approval (ADR-073).',
            'default_steps' => AiDefaultSteps::analysis(),
        ]);
    }
}
