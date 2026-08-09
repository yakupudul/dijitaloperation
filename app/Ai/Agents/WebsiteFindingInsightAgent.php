<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use MoxDop\Website\Ai\Agents\WebsiteRecommendationAgent;
use Stringable;

/**
 * @deprecated Prefer MoxDop\Website\Ai\Agents\WebsiteRecommendationAgent.
 * Legacy Core agent kept as a thin schema-compatible alias for older fakes/tests.
 * Production orchestration uses WebsiteRecommendationAgent in app-modules/website.
 */
class WebsiteFindingInsightAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    private WebsiteRecommendationAgent $delegate;

    public function __construct()
    {
        $this->delegate = new WebsiteRecommendationAgent;
    }

    public function instructions(): Stringable|string
    {
        return $this->delegate->instructions();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->delegate->schema($schema);
    }
}
