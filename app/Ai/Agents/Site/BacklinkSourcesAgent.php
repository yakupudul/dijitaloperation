<?php

namespace App\Ai\Agents\Site;

use App\Ai\Concerns\UsesPromptRegistry;
use App\Ai\Contracts\RegistryPrompted;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Backlinkler "AI ile kaynak öner" (operation `backlinks.sources`): potential link sources for one brand by sector +
 * service areas (directories, associations, local news, chambers …). A fee is ücretsiz / ücretli only with an evidence
 * URL; otherwise "teyit gerekli".
 */
final class BacklinkSourcesAgent implements Agent, HasProviderOptions, HasStructuredOutput, RegistryPrompted
{
    use Promptable;
    use UsesPromptRegistry;

    public const string OPERATION = AiRouteKeys::BACKLINKS_SOURCES;

    public const string PROMPT_VERSION = 'backlinks-sources-v1';

    public const array KINDS = ['dizin', 'dernek', 'yerel_haber', 'oda', 'diger'];

    public const array FEES = ['ucretsiz', 'ucretli', 'teyit'];

    public function promptOperation(): string
    {
        return self::OPERATION;
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'sources' => $schema->array()->items($schema->object(fn (JsonSchema $row): array => [
                'name' => $row->string()->required(),
                'url' => $row->string()->required(),
                'kind' => $row->string()->enum(self::KINDS)->required(),
                'fee' => $row->string()->enum(self::FEES)->required(),
                'fee_evidence_url' => $row->string()->nullable()->required(),
                'reason' => $row->string()->required(),
            ]))->required(),
            'prompt_version' => $schema->string()->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return $key === Lab::OpenAI->value ? ['store' => false] : [];
    }
}
