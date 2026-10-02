<?php

namespace App\Ai\Agents\Site;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Models\Cluster;
use App\Support\Ai\AiProviderOptions;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Rakipler "Analiz et" (operation `competitors.analyze`): one cluster — our target page (or "sayfa yok") and the fetched
 * competitor pages → which need they meet, dominant page type, useful info we lack, local / trust elements, improve vs
 * new page, and suggestions. Each suggestion must cite ≥ 2 competitor URLs from the input, or be a clear gap (no page).
 */
final class CompetitorAnalyzeAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::COMPETITORS_ANALYZE;

    public const string PROMPT_VERSION = 'competitors-analyze-v1';

    public const array DECISIONS = ['improve', 'new_page'];

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'need' => $schema->string()->required(),
            'dominant_page_type' => $schema->string()->enum(Cluster::PAGE_TYPES)->required(),
            'missing_info' => $schema->array()->items($schema->string())->required(),
            'local_trust' => $schema->array()->items($schema->string())->required(),
            'decision' => $schema->string()->enum(self::DECISIONS)->required(),
            'suggestions' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'title' => $row->string()->required(),
                'reason' => $row->string()->required(),
                'competitor_urls' => $row->array()->items($row->string())->required(),
                'gap' => $row->boolean()->required(),
            ]))->required(),
            'prompt_version' => $schema->string()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
