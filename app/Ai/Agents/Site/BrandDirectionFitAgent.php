<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `brand.direction_fit` (Marka rotası): after a brand's direction changed, its clusters and unsent content ideas are read
 * once against the CURRENT direction (summary, positioning, audiences, services): fits, off_direction or unsure.
 */
final class BrandDirectionFitAgent extends SiteAgent
{
    public const array VERDICTS = ['fits', 'off_direction', 'unsure'];

    public const array KINDS = ['cluster', 'idea'];

    public function promptOperation(): string
    {
        return AiRouteKeys::BRAND_DIRECTION_FIT;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'kind' => $row->string()->enum(self::KINDS)->required(),
                'id' => $row->integer()->required(),
                'verdict' => $row->string()->enum(self::VERDICTS)->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
        ];
    }
}
