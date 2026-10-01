<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `compliance.forbidden_terms` ("AI ile öner"): candidate forbidden phrases for content of one sector; the operator approves each. */
final class ForbiddenTermsAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::COMPLIANCE_FORBIDDEN_TERMS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'terms' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'phrase' => $row->string()->required(),
                'reason' => $row->string()->required(),
                'severity' => $row->string()->enum(['block', 'warn'])->required(),
            ]))->required(),
        ];
    }
}
