<?php

namespace App\Ai\Agents\Site;

use App\Models\Page;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.page_categories`: one batched call puts the pages the rules could not place into a category. */
final class PageCategoriesAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_PAGE_CATEGORIES;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pages' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'page_id' => $row->integer()->required(),
                'category' => $row->string()->enum(Page::CATEGORIES)->required(),
            ]))->required(),
        ];
    }
}
