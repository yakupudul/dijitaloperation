<?php

namespace App\Ai\Agents\Site;

use App\Services\Site\SiteSuggestionTypes;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.url_analysis`: suggestions for one URL, each with evidence from the data pack (quotes, numbers, URLs). */
final class UrlAnalysisAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_URL_ANALYSIS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'suggestions' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'type' => $row->string()->enum(array_keys(SiteSuggestionTypes::ANALYSIS))->required(),
                'title' => $row->string()->required(),
                'reason' => $row->string()->required(),
                'priority' => $row->integer()->required(),
                'cluster_id' => $row->integer()->nullable()->required(),
                'evidence' => $row->array()->items($row->object(fn (JsonSchema $item): array => [
                    'kind' => $item->string()->enum(['quote', 'number', 'url'])->required(),
                    'value' => $item->string()->required(),
                    'source' => $item->string()->required(),
                ]))->required(),
            ]))->required(),
        ];
    }
}
