<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `site.doorway_check` (Kapı sayfaları): template pages of one site (the same contact table under every title), read
 * once — does the title name another business or person (other_business), a place the business does not serve
 * (out_of_area), or a real topic of the business in its own area (keep)?
 */
final class DoorwayCheckAgent extends SiteAgent
{
    public const array VERDICTS = ['keep', 'other_business', 'out_of_area', 'unsure'];

    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_DOORWAY_CHECK;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'id' => $row->integer()->required(),
                'verdict' => $row->string()->enum(self::VERDICTS)->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
        ];
    }
}
