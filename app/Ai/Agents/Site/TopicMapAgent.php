<?php

namespace App\Ai\Agents\Site;

use App\Models\ContentTopic;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `content.topic_map` (konu haritası): one main page and 8–15 questions of one service of a site, each placed on the page that answers it today. */
final class TopicMapAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::CONTENT_TOPIC_MAP;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topics' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'type' => $row->string()->enum(ContentTopic::TYPES)->required(),
                'question' => $row->string()->required(),
                'title' => $row->string()->required(),
                'cluster_id' => $row->integer()->nullable()->required(),
                'covered_by_url' => $row->string()->nullable()->required(),
                'coverage' => $row->string()->enum(ContentTopic::COVERAGES)->required(),
                'season_month' => $row->integer()->nullable()->required(),
            ]))->required(),
        ];
    }
}
