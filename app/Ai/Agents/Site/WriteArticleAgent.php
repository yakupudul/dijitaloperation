<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.write_article` ("Taslak hazırla"): full article HTML + SEO fields for one content suggestion. */
final class WriteArticleAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_WRITE_ARTICLE;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'slug' => $schema->string()->required(),
            'meta_title' => $schema->string()->required(),
            'meta_description' => $schema->string()->required(),
            'excerpt' => $schema->string()->required(),
            'html' => $schema->string()->required(),
        ];
    }
}
