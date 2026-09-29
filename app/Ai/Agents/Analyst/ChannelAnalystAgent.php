<?php

namespace App\Ai\Agents\Analyst;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Brand workspace analyst (one per channel run): reads the channel pack and returns the week's decisions as cards.
 * The channel part of the instructions comes from the ChannelAnalyst; the frame is the `analyst.<channel>` prompt
 * (PromptRegistry, default config moxdop-prompts.channel_analyst); the output shape is shared.
 */
final class ChannelAnalystAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string PROMPT_VERSION = 'channel-analyst-v1';

    /** @param  list<string>  $actionTypes */
    public function __construct(
        private readonly string $operation = 'analyst.search',
        private readonly string $channelInstructions = '',
        private readonly array $actionTypes = [],
    ) {}

    public function promptOperation(): string
    {
        return $this->operation;
    }

    /** @return array<string, string> */
    protected function promptVariables(): array
    {
        return [
            'channel_instructions' => $this->channelInstructions,
            'allowed_actions' => $this->actionTypes === [] ? '' : "\nAllowed action types: ".implode(', ', $this->actionTypes).'.',
        ];
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'decisions' => $schema->array()->items($schema->object(fn (JsonSchema $item): array => [
                'key' => $item->string()->required(),
                'title_tr' => $item->string()->required(),
                'why_tr' => $item->string()->required(),
                'priority' => $item->integer()->required(),
                'effort' => $item->string()->enum(['low', 'medium', 'high'])->required(),
                'impact' => $item->object(fn (JsonSchema $impact): array => [
                    'estimate' => $impact->string()->required(),
                    'basis' => $impact->string()->required(),
                ])->required(),
                'evidence_refs' => $item->array()->items($item->string())->required(),
                'action' => $item->object(fn (JsonSchema $action): array => [
                    'type' => $action->string()->required(),
                    'params' => $action->object(fn (JsonSchema $params): array => [
                        'target' => $params->string()->required(),
                    ])->required(),
                ])->required(),
            ]))->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
