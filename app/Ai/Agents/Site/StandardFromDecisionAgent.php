<?php

namespace App\Ai\Agents\Site;

use App\Services\Site\ScopedStandards;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.standard_from_decision` ("Bu karardan standart öner"): a reusable, scoped standard from one approved decision. */
final class StandardFromDecisionAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_STANDARD_FROM_DECISION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'rule' => $schema->string()->required(),
            'condition' => $schema->string()->required(),
            'exceptions' => $schema->string()->required(),
            'scope' => $schema->string()->enum(ScopedStandards::SCOPES)->required(),
        ];
    }
}
