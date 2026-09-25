<?php

namespace App\Ai\Agents\Brain;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Brain: assigns search queries the rules could not place to one service and one search intent. */
final class QueryServiceClassifierAgent extends BrainAgent
{
    public const array INTENTS = ['transactional', 'commercial', 'informational', 'local', 'navigational'];

    protected function task(): string
    {
        return <<<'TASK'
Assign Turkish (or other language) search queries to the service they ask about. INPUT_JSON has `services` (id, name,
description, example queries already approved for it) and `queries` (id, text, and the service the similarity model
thought closest, which may be wrong).
For each query return: `query_id`; `service_id` = the ONE service it is about, or null when it is about none of them,
is too vague, or is only a brand / person name; `intent`:
- transactional: wants to buy / book / get a price ("implant fiyatı", "randevu")
- local: service + place or "yakınımda" ("kadıköy implant")
- commercial: compares or evaluates ("en iyi implant markası", "zirkonyum mu porselen mi")
- informational: wants to learn ("implant ağrılı mı", "implant nedir")
- navigational: looks for a specific business or site
and `reason`. Do not force a service: null is a good answer when unsure.
TASK;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'query_id' => $item->integer()->required(),
                'service_id' => $item->integer()->nullable()->required(),
                'intent' => $item->string()->enum(self::INTENTS)->required(),
                'reason' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
