<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.cluster_match`: which page of the site answers each cluster of one service, judged from the page content. */
final class ClusterMatchAgent extends SiteAgent
{
    public const array COVERAGE = ['full', 'partial', 'none'];

    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_CLUSTER_MATCH;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clusters' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'cluster_id' => $row->integer()->required(),
                'page_id' => $row->integer()->nullable()->required(),
                'coverage' => $row->string()->enum(self::COVERAGE)->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
        ];
    }
}
