<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiProviderOptions;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Sorular ve yorumlar: one question a visitor asked on a page of the brand's site. Sorts it (publish / private / spam),
 * cleans it of anything personal and writes the short public answer from the brand's verified record. The answer
 * waits on the Onarım masası; nothing reaches the site without the operator's approval.
 */
final class SiteQaAnswerAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public function promptOperation(): string
    {
        return AiRouteKeys::SITE_QA_ANSWER;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()->enum(['publish', 'private', 'spam'])->required(),
            'question' => $schema->string()->required(),
            'answer' => $schema->string()->required(),
            'link_url' => $schema->string()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiProviderOptions::for((string) $key);
    }
}
