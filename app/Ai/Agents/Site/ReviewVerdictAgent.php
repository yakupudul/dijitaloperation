<?php

namespace App\Ai\Agents\Site;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A second reading of work MoxDOP prepared by itself (yakup, 2026-10-14): one verdict per item, ok / wrong / unsure,
 * with a short Turkish reason. Only "ok" reaches the operator's daily one-click send; the caller checks the ids.
 */
abstract class ReviewVerdictAgent extends SiteAgent
{
    public const array VERDICTS = ['ok', 'wrong', 'unsure'];

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
