<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.translation_fix` (bozuk çeviri onarımı): the page's language version translated faithfully from its source. */
final class TranslationFixAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_TRANSLATION_FIX;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'seo_title' => $schema->string()->nullable()->required(),
            'meta_description' => $schema->string()->nullable()->required(),
            'html' => $schema->string()->required(),
            'note' => $schema->string()->required(),
        ];
    }
}
