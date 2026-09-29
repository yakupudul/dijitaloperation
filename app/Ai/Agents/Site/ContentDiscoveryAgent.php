<?php

namespace App\Ai\Agents\Site;

use App\Services\Site\ContentPlanner;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.content_discovery` ("Kümeler dışında fırsat keşfet"): content opportunities from queries outside every cluster. */
final class ContentDiscoveryAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_CONTENT_DISCOVERY;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'title' => $row->string()->required(),
                'service_id' => $row->integer()->nullable()->required(),
                'query_ids' => $row->array()->items($row->integer())->required(),
                'new_queries' => $row->array()->items($row->string())->required(),
                'page_type' => $row->string()->enum(ContentPlanner::PAGE_TYPES)->required(),
                'outline' => $row->array()->items($row->string())->required(),
                'questions' => $row->array()->items($row->string())->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
        ];
    }
}
