<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `content.brief`: before a new article is written, what Google shows for its search and what the article must do better. */
final class ContentBriefAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::CONTENT_BRIEF;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'searched' => $schema->boolean()->required(),
            'intent' => $schema->string()->required(),
            'format' => $schema->string()->required(),
            'top_results' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'title' => $row->string()->required(),
                'url' => $row->string()->required(),
                'covers' => $row->array()->items($row->string())->required(),
            ]))->required(),
            'people_also_ask' => $schema->array()->items($schema->string())->required(),
            'must_cover' => $schema->array()->items($schema->string())->required(),
            'gaps' => $schema->array()->items($schema->string())->required(),
            'brand_element' => $schema->string()->required(),
            'outline' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
