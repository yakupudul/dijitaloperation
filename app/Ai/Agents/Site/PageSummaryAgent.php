<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.page_summary` (brand memory): 2–4 sentence summary + key facts per page, only from the page text. */
final class PageSummaryAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_PAGE_SUMMARY;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pages' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'page_id' => $row->integer()->required(),
                'summary' => $row->string()->required(),
                'facts' => $row->array()->items($row->string())->required(),
            ]))->required(),
        ];
    }
}
