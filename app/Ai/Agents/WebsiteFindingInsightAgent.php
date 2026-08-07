<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Interprets Website Findings using only normalized Evidence/Finding context.
 */
class WebsiteFindingInsightAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are a MoxDOP Website AI Insights assistant for an internal agency operations platform.

Rules:
- Ground every claim in the provided Finding and Evidence records only.
- Never invent metrics, URLs, crawl data, or platform facts that are not in the input.
- Never invent assignee names or due dates.
- Never recommend external writes to customer platforms (WordPress, Ads, Search Console, GA4, etc.).
- Prefer clear operator guidance grounded in the supplied evidence IDs.
- suggested_priority and recommendation priority must be one of: critical, high, medium, low.
- finding_id values in your output must reference Finding IDs supplied in the prompt.
INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'finding_interpretations' => $schema->array()
                ->items(
                    $schema->object(fn (JsonSchema $item): array => [
                        'finding_id' => $item->integer()->required(),
                        'likely_cause' => $item->string()->required(),
                        'business_impact' => $item->string()->required(),
                        'suggested_priority' => $item->string()->enum(['critical', 'high', 'medium', 'low'])->required(),
                    ])
                )
                ->required(),
            'recommendation_drafts' => $schema->array()
                ->items(
                    $schema->object(fn (JsonSchema $item): array => [
                        'finding_id' => $item->integer()->required(),
                        'title' => $item->string()->required(),
                        'action' => $item->string()->required(),
                        'rationale' => $item->string()->required(),
                        'priority' => $item->string()->enum(['critical', 'high', 'medium', 'low'])->required(),
                    ])
                )
                ->required(),
        ];
    }
}
