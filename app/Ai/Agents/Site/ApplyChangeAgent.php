<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.apply_change` ("AI ile yap"): the new version of the fields / page HTML one suggestion changes. */
final class ApplyChangeAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_APPLY_CHANGE;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'seo_title' => $schema->string()->nullable()->required(),
            'meta_description' => $schema->string()->nullable()->required(),
            'html' => $schema->string()->nullable()->required(),
            'internal_links' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'anchor' => $row->string()->required(),
                'url' => $row->string()->required(),
            ]))->required(),
            'schema_json' => $schema->string()->nullable()->required(),
            'note' => $schema->string()->required(),
        ];
    }
}
