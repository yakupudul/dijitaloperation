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
 * İşletme Profili "Hizmetleri karşılaştır" (operation `gbp.services_compare`): the brand's approved services vs the
 * profile's primary / additional categories and services list. Names are checked against the input by
 * GbpAssistant; nothing is written to Google.
 */
final class GbpServicesCompareAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::GBP_SERVICES_COMPARE;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'missing_services' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'name' => $row->string()->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
            'category_notes' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'category' => $row->string()->required(),
                'note' => $row->string()->required(),
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
