<?php

namespace App\Ai\Agents\Brain;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Brain: reads one service page and fills a fixed yes/no checklist, so pages of different brands can be compared on
 * the same terms. Judging, not writing: no advice, no scores.
 */
final class PageFeatureAgent extends BrainAgent
{
    public const array CHECKS = [
        'answer_first' => 'The first paragraph directly answers what the service is / who it is for in under ~60 words',
        'question_headings' => 'Several H2/H3 headings are questions people search, each followed by a self-contained answer',
        'process_steps' => 'The treatment / service process is explained step by step',
        'duration_recovery' => 'Duration, healing or recovery time is explained',
        'risks_side_effects' => 'Risks, side effects or who should not have it are explained honestly',
        'candidacy' => 'Who is a good candidate is explained',
        'expert_shown' => 'A named professional (doctor / expert) wrote or reviewed the page, with credentials',
        'review_date' => 'A written or reviewed / updated date is visible',
        'sources_cited' => 'Statistics, studies or authoritative sources are cited',
        'clear_next_step' => 'A clear next step is offered (call, form, WhatsApp, booking)',
        'local_context' => 'The location / area served and how to reach it are stated',
    ];

    protected function task(): string
    {
        $list = implode("\n", array_map(fn (string $k, string $v): string => '- '.$k.': '.$v, array_keys(self::CHECKS), self::CHECKS));

        return <<<TASK
INPUT_JSON has one web page (url, the service and the topic it should cover, and its visible text). For each check
below return true only when the page clearly does it; false when absent or unclear. Also return `subtopics_missing`:
up to 6 short Turkish sub-questions of the topic (from `topic_queries`) the page does not answer.
$list
TASK;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $fields = [];
        foreach (array_keys(self::CHECKS) as $key) {
            $fields[$key] = $schema->boolean()->required();
        }

        return $fields + ['subtopics_missing' => $schema->array()->items($schema->string())->required()];
    }
}
