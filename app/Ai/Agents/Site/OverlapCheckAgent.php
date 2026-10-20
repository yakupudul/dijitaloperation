<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `overlap.check` (Aynı konuyu anlatan sayfalar): open cluster overlaps of one site, read once — do the two pages answer
 * the same question (same), a different question of the same subject (different), or nothing alike (unrelated)?
 */
final class OverlapCheckAgent extends SiteAgent
{
    public const array VERDICTS = ['same', 'different', 'unrelated', 'unsure'];

    public function promptOperation(): string
    {
        return AiRouteKeys::OVERLAP_CHECK;
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
