<?php

namespace App\Ai\Agents\Site;

use App\Services\Site\ContentPlanner;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.weekly_content` ("Haftalık içerik öner"): this week's new pages / posts and updates, within the capacity. */
final class WeeklyContentAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_WEEKLY_CONTENT;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'language' => $row->string()->required(),
                'title' => $row->string()->required(),
                'kind' => $row->string()->enum(['new', 'update'])->required(),
                'cluster_id' => $row->integer()->nullable()->required(),
                'query' => $row->string()->nullable()->required(),
                'page_type' => $row->string()->enum(ContentPlanner::PAGE_TYPES)->required(),
                'target_url' => $row->string()->nullable()->required(),
                'angle' => $row->string()->enum(array_keys(ContentPlanner::ANGLES))->required(),
                'outline' => $row->array()->items($row->string())->required(),
                'questions' => $row->array()->items($row->string())->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
        ];
    }
}
