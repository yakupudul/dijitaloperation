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
 * Google Ads "Reklam metni yaz" (operation `google_ads.ad_texts`): one responsive search ad (headlines ≤ 30,
 * descriptions ≤ 90 characters) for one ad group and its landing page from the brand's own pages. Limits, URLs and
 * sector compliance are enforced by GoogleAdsAssistant; approved drafts go to the Editor file.
 */
final class GoogleAdsAdTextsAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::GOOGLE_ADS_AD_TEXTS;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'headlines' => $schema->array()->items($schema->string())->required(),
            'descriptions' => $schema->array()->items($schema->string())->required(),
            'path1' => $schema->string()->required(),
            'path2' => $schema->string()->required(),
            'final_url' => $schema->string()->required(),
            'landing_reason' => $schema->string()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
