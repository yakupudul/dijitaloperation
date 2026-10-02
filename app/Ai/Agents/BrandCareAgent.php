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
 * Marka bakım ajanı (operation `brand.care`): one active brand, weekly, only when its Marka dosyası changed. Reads the
 * dossier, the changed sections and its own previous notes; returns a short summary, at most 5 tasks for the work list
 * and the facts it needs from the operator. Validated by BrandCare before anything is stored.
 */
final class BrandCareAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::BRAND_CARE;

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'tasks' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'title' => $row->string()->required(),
                'why' => $row->string()->required(),
                'channel' => $row->string()->enum(['search', 'maps', 'google_ads', 'meta'])->required(),
                'priority' => $row->integer()->min(1)->max(3)->required(),
            ]))->required(),
            'questions' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
