<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `site.image_alts`: alt text for WordPress images without one, from the file name and the page the image is on. */
final class ImageAltsAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_IMAGE_ALTS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'images' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'image_id' => $row->integer()->required(),
                'alt' => $row->string()->required(),
            ]))->required(),
        ];
    }
}
