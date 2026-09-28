<?php

namespace App\Ai\Agents\Queries;

use App\Ai\Agents\Brain\BrainAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Sorgu hattı: the sector of each discovered account / website (many per call; the operator can change it later). */
final class AssetSectorAgent extends BrainAgent
{
    public const string PROMPT_VERSION = 'asset-sector-v1';

    protected function task(): string
    {
        return <<<'TASK'
An agency discovered digital accounts (Search Console properties, GA4 properties, Business Profile locations, Google
Ads and Meta ad accounts) and websites. INPUT_JSON has `sectors` (code + label) and `assets` (key, type, name, web
address, Business Profile category, the brand it belongs to if any, and its most searched queries).
For every asset return one item: `key` (unchanged), `sector` = ONE code from `sectors` that describes the business
behind the asset, or null when the signals do not show it; `confidence` between 0 and 1; `reason`.
Search queries and the Business Profile category are the strongest signals; account names often only repeat the
brand. Never invent a code.
TASK;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'key' => $item->string()->required(),
                'sector' => $item->string()->nullable()->required(),
                'confidence' => $item->number()->required(),
                'reason' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
