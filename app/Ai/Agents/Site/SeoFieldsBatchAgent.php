<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.seo_fields_batch` (Onarım masası, toplu hazırlık): SEO titles and meta descriptions of up to 15 pages of one site in one call. */
final class SeoFieldsBatchAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_SEO_FIELDS_BATCH;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'pages' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'id' => $row->integer()->required(),
                'seo_title' => $row->string()->nullable()->required(),
                'meta_description' => $row->string()->nullable()->required(),
            ]))->required(),
        ];
    }
}
