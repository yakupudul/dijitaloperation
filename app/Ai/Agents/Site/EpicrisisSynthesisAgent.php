<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `brand.epicrisis_synthesis` (marka epikrizi): positioning, audiences, objections, proof, voice and channel messages, each line resting on the record's own lines. */
final class EpicrisisSynthesisAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::BRAND_EPICRISIS_SYNTHESIS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'kind' => $row->string()->enum(array_keys((array) config('moxdop-epicrisis.synthesis')))->required(),
                'text' => $row->string()->required(),
                'answer' => $row->string()->nullable()->required(),
                'refs' => $row->array()->items($row->integer())->required(),
            ]))->required(),
        ];
    }
}
