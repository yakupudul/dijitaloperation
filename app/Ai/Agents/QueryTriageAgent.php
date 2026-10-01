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
 * Sorgu otomatik pilotu (operation `queries.triage`): one call per batch of unassigned queries of ONE sector decides per
 * query — a service of the sector, a filter term (person name, brand name, irrelevant search, forbidden phrase) or
 * none — and may add matching keywords. Applied without approval; every query is asked once.
 */
final class QueryTriageAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_TRIAGE;

    public const array FILTER_REASONS = ['person_name', 'brand_name', 'irrelevant', 'forbidden'];

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'decisions' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'query_id' => $r->integer()->required(),
                'service_id' => $r->integer()->nullable()->required(),
                'filter_term' => $r->string()->nullable()->required(),
                'filter_reason' => $r->string()->enum([...self::FILTER_REASONS, 'none'])->required(),
            ]))->required(),
            'keywords' => $schema->array()->items($schema->object(fn (JsonSchema $r): array => [
                'service_id' => $r->integer()->required(),
                'keyword' => $r->string()->required(),
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
