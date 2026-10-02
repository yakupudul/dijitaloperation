<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiProviderOptions;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Google Ads "Kampanya yapısı öner" (operation `google_ads.structure`): campaign / ad group structure by the brand's
 * main services (SEO clusters are an input, not automatic ad groups), the daily budget split and an experiment plan.
 * GoogleAdsAssistant validates services, URLs and budgets against the data pack; approved drafts go to the Editor file.
 */
final class GoogleAdsStructureAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::GOOGLE_ADS_STRUCTURE;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'campaigns' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'name' => $row->string()->required(),
                'service' => $row->string()->required(),
                'daily_budget' => $row->number()->required(),
                'reason' => $row->string()->required(),
                'ad_groups' => $row->array()->items($row->object(fn (JsonSchema $group): array => [
                    'name' => $group->string()->required(),
                    'landing_url' => $group->string()->required(),
                    'keywords' => $group->array()->items($group->object(fn (JsonSchema $keyword): array => [
                        'text' => $keyword->string()->required(),
                        'match_type' => $keyword->string()->enum(['EXACT', 'PHRASE', 'BROAD'])->required(),
                    ]))->required(),
                ]))->required(),
            ]))->required(),
            'budget_split' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'service' => $row->string()->required(),
                'daily_budget' => $row->number()->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
            'experiments' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'title' => $row->string()->required(),
                'hypothesis' => $row->string()->required(),
                'metric' => $row->string()->required(),
                'duration_days' => $row->integer()->required(),
            ]))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
