<?php

namespace App\Ai\Agents\Brain;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Brain: says which service and which message angle each Meta ad is about. */
final class CreativeClassifierAgent extends BrainAgent
{
    public const array ANGLES = ['price_offer', 'trust_expertise', 'result_benefit', 'pain_problem', 'social_proof', 'education', 'urgency', 'other'];

    protected function task(): string
    {
        return <<<'TASK'
INPUT_JSON has a brand's `services` (id, name) and Meta `ads` (id, ad / ad set / campaign names, the creative's title
and text). For every ad return: `ad_id`; `service_id` = the ONE service the ad promotes, or null when it is general
brand advertising or unclear; `angle` = the main message angle:
- price_offer: price, discount, campaign, instalments
- trust_expertise: doctor / team / technology / experience
- result_benefit: the outcome the person gets (a new smile, comfort, looks)
- pain_problem: the problem or fear the person has
- social_proof: patient stories, numbers of patients, ratings
- education: explains the treatment or process
- urgency: limited time / places
- other
and `reason`.
TASK;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'ad_id' => $item->string()->required(),
                'service_id' => $item->integer()->nullable()->required(),
                'angle' => $item->string()->enum(self::ANGLES)->required(),
                'reason' => $item->string()->required(),
            ]))->required(),
        ];
    }
}
