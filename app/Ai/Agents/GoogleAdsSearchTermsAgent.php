<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Google Ads "Arama terimlerini incele" (operation `google_ads.search_terms`): intent and service fit of each search
 * term, and negative keyword proposals (match type, scope, the useful queries each could block). GoogleAdsAssistant
 * validates every term, name and number against the data pack before anything is shown.
 */
final class GoogleAdsSearchTermsAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::GOOGLE_ADS_SEARCH_TERMS;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'terms' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'term' => $row->string()->required(),
                'intent' => $row->string()->enum(['ticari', 'bilgi', 'marka', 'rakip', 'alakasiz'])->required(),
                'service' => $row->string()->required(),
                'fit' => $row->string()->enum(['uygun', 'kismen', 'uygunsuz'])->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
            'negatives' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'text' => $row->string()->required(),
                'match_type' => $row->string()->enum(['EXACT', 'PHRASE', 'BROAD'])->required(),
                'scope' => $row->string()->enum(['shared', 'campaign', 'ad_group'])->required(),
                'campaign' => $row->string()->required(),
                'ad_group' => $row->string()->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
