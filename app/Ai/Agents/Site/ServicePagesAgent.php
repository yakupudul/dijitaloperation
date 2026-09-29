<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.service_pages` (AI adım 1): hizmet / lokasyon pages the name rules could not match → one brand service or none. */
final class ServicePagesAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_SERVICE_PAGES;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pages' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'page_id' => $row->integer()->required(),
                'service_id' => $row->integer()->nullable()->required(),
            ]))->required(),
        ];
    }
}
