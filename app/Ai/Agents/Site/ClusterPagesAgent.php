<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.cluster_pages` (AI adım 2): coverage / intent judgement for the ambiguous clusters of one service. */
final class ClusterPagesAgent extends SiteAgent
{
    /** States the model may decide; performance / conflict / data states stay deterministic. */
    public const array STATES = ['no_page', 'thin_coverage', 'wrong_page', 'sufficient'];

    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_CLUSTER_PAGES;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clusters' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'cluster_id' => $row->integer()->required(),
                'page_id' => $row->integer()->nullable()->required(),
                'state' => $row->string()->enum(self::STATES)->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
        ];
    }
}
