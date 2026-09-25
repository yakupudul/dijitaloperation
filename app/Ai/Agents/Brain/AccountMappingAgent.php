<?php

namespace App\Ai\Agents\Brain;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Brain: suggests the sector and the services of each Google account (Ads, Search Console, Business Profile). */
final class AccountMappingAgent extends BrainAgent
{
    protected function task(): string
    {
        return <<<'TASK'
An agency connects Google accounts (Google Ads, Search Console, Business Profile) and must say, for each account,
which sector it belongs to and which services of that sector its search queries are about. INPUT_JSON has:
`sectors` (code + label), `services` (id, sector code, name) and `accounts` (id, type, name, bound brand and website
if any, and its most frequent search queries with impressions).
For every account return one item: `account_id`, `sector` (one code from `sectors`, or null when the queries do not
show the business), `service_ids` (ids from `services` of THAT sector that the queries clearly ask for; most
important first; empty when none) and `reason`. Brand names and navigational queries say little; look at what people
search for. Do not pick a service only because one rare query mentions it.
TASK;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'account_id' => $item->integer()->required(),
                'sector' => $item->string()->nullable()->required(),
                'service_ids' => $item->array()->items($item->integer())->required(),
                'reason' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
