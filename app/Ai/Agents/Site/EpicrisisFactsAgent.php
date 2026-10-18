<?php

namespace App\Ai\Agents\Site;

use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** `brand.epicrisis_facts` (marka epikrizi): what only the business says about itself on its key pages and profiles, each quoted word for word. */
final class EpicrisisFactsAgent extends SiteAgent
{
    public function promptOperation(): string
    {
        return AiRouteKeys::BRAND_EPICRISIS_FACTS;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'facts' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'field' => $row->string()->required(),
                'scope' => $row->string()->nullable()->required(),
                'value' => $row->string()->required(),
                'source' => $row->string()->required(),
                'quote' => $row->string()->required(),
            ]))->required(),
        ];
    }
}
