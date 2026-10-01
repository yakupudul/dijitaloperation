<?php

namespace App\Ai\Agents\Site;

use App\Models\ContentIdea;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `content.ideas` ("Yeni fikir üret"): extra content ideas of one cluster, each needing its own page. */
final class ContentIdeasAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::CONTENT_IDEAS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ideas' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'title' => $row->string()->required(),
                'type' => $row->string()->enum(ContentIdea::TYPES)->required(),
                'angle' => $row->string()->required(),
                'target_queries' => $row->array()->items($row->string())->required(),
                'outline' => $row->array()->items($row->string())->required(),
            ]))->required(),
        ];
    }
}
