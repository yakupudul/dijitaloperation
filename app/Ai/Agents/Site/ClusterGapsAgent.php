<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.cluster_gaps`: what one page does not answer of the clusters it is the target of. */
final class ClusterGapsAgent extends SiteAgent
{
    public const array KINDS = ['soru', 'bolum', 'yon', 'lokasyon', 'ai_sorusu'];

    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_CLUSTER_GAPS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'clusters' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'cluster_id' => $row->integer()->required(),
                'coverage' => $row->string()->enum(ClusterMatchAgent::COVERAGE)->required(),
                'gaps' => $row->array()->items($row->object(fn (JsonSchema $gap): array => [
                    'text' => $gap->string()->required(),
                    'kind' => $gap->string()->enum(self::KINDS)->required(),
                ]))->required(),
            ]))->required(),
        ];
    }
}
