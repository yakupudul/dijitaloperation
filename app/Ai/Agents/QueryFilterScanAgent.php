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
 * Filtre sepeti "Sorgularda tara" (operation `queries.scan_filters`): classifies the candidate words of a sector's
 * queries (one example query each) and returns only those that belong in the filter basket: brand / company, person,
 * place or other off-topic words. Proposal only; the operator approves.
 */
final class QueryFilterScanAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_SCAN_FILTERS;

    public const string PROMPT_VERSION = 'queries-scan-filters-v1';

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'words' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'word' => $r->string()->required(),
                'category' => $r->string()->enum(['brand', 'person', 'place', 'other'])->required(),
                'reason' => $r->string()->required(),
            ]))->required(),
            'prompt_version' => $schema->string()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
