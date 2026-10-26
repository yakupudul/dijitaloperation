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
 * Sorgu otomatik pilotu (operation `queries.triage`): one call per batch of unassigned queries of ONE sector decides per
 * query — a service of the sector, a filter term (person name, brand / product brand, place, irrelevant search,
 * forbidden phrase) or a service the catalog lacks — and may add matching keywords. Applied without approval; every
 * query is asked once and leaves the unassigned list (2026-10-26: "none" left 36k queries waiting for weeks).
 */
final class QueryTriageAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::QUERIES_TRIAGE;

    public const array FILTER_REASONS = ['person_name', 'brand_name', 'place', 'irrelevant', 'forbidden'];

    /** Relevant to the sector but no catalog service answers it: `filter_term` carries the missing service's short name, which is created in the catalog. */
    public const string NEW_SERVICE = 'new_service';

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
                'filter_reason' => $r->string()->enum([...self::FILTER_REASONS, self::NEW_SERVICE, 'none'])->required(),
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

        return AiProviderOptions::for((string) $key);
    }
}
